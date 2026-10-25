<?php

use App\Domain\Integrations\HomeAssistant\HomeAssistantWebhooks;
use App\Domain\Rewards\RewardService;
use App\Jobs\SendHomeAssistantWebhook;
use App\Models\HaAuditEntry;
use App\Models\HaWebhook;
use App\Models\RewardItem;
use App\Models\TaskCompletion;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    [$this->family, $this->parent, $this->child] = makeFamily();
    $this->webhook = HaWebhook::create(['family_id' => $this->family->id, 'url' => 'https://ha.example/api/webhook/hh', 'secret' => 'geheim']);
    Http::fake(['ha.example/*' => Http::response('', 200)]);
});

/** @return list<array{event: string, data: array, request: HttpRequest}> */
function sentWebhooks(): array
{
    return Http::recorded()->map(fn ($pair) => [
        'event' => $pair[0]->header('X-Heldenhaus-Event')[0] ?? null,
        'data' => $pair[0]->data()['data'] ?? [],
        'request' => $pair[0],
    ])->all();
}

it('meldet Abschlüsse, „alle Dailies erledigt“ und Level-ups signiert an HA', function () {
    $daily = makeTask($this->child, ['type' => 'daily', 'title' => 'Zähne putzen', 'repeat' => ['type' => 'daily']]);

    $this->actingAs($this->child)->post('/assignments/'.assignmentOf($daily, $this->child)->id.'/score/up');

    $events = collect(sentWebhooks());
    expect($events->pluck('event')->all())->toContain('task_completed', 'all_dailies_done')
        ->and($events->firstWhere('event', 'task_completed')['data'])->toMatchArray(['member_name' => 'Lea', 'task_title' => 'Zähne putzen', 'source' => 'web']);

    $request = $events->first()['request'];
    expect(HomeAssistantWebhooks::verify('geheim', $request->body(), $request->header('X-Heldenhaus-Timestamp')[0], $request->header('X-Heldenhaus-Signature')[0]))->toBeTrue()
        ->and(HomeAssistantWebhooks::verify('falsch', $request->body(), $request->header('X-Heldenhaus-Timestamp')[0], $request->header('X-Heldenhaus-Signature')[0]))->toBeFalse();

    // „alle Dailies erledigt“ kommt nur einmal am Tag, auch nach Rückgängig + erneutem Abhaken
    $id = assignmentOf($daily, $this->child)->id;
    $this->actingAs($this->child)->post("/assignments/{$id}/score/down");
    $this->actingAs($this->child)->post("/assignments/{$id}/score/up");
    expect(collect(sentWebhooks())->where('event', 'all_dailies_done'))->toHaveCount(1);
});

it('verrät private Aufgaben nicht und meldet wartende Freigaben', function () {
    $private = makeTask($this->child, ['type' => 'habit', 'title' => 'Tagebuch', 'is_private' => true, 'up' => true]);
    $family = makeTask($this->parent, [
        'scope' => 'family', 'type' => 'todo', 'title' => 'Müll', 'assignment_mode' => 'assigned',
        'assignees' => [$this->child->id], 'approval_mode' => 'required', 'star_reward' => 2,
    ]);

    $this->actingAs($this->child)->post('/assignments/'.assignmentOf($private, $this->child)->id.'/score/up');
    $this->actingAs($this->child)->post('/assignments/'.assignmentOf($family, $this->child)->id.'/score/up');

    $events = collect(sentWebhooks());
    expect($events->first()['data']['task_title'])->toBeNull()
        ->and($events->first()['data']['category'])->toBeNull()
        ->and($events->pluck('event'))->toContain('approval_requested');
});

it('löst beim Einlösen einer Belohnung die Smart-Home-Aktion aus', function () {
    $item = RewardItem::create(['family_id' => $this->family->id, 'title' => '30 Minuten Konsole', 'star_cost' => 2, 'ha_action_key' => 'screen_time_30']);
    $this->child->stats()->update(['stars' => 5]);

    $redemption = app(RewardService::class)->redeem($item, $this->child);
    expect(collect(sentWebhooks())->where('event', 'reward_redeemed'))->toBeEmpty(); // wartet auf Eltern

    app(RewardService::class)->review($redemption, $this->parent, true);
    $event = collect(sentWebhooks())->firstWhere('event', 'reward_redeemed');

    expect($event['data'])->toMatchArray(['action_key' => 'screen_time_30', 'member_name' => 'Lea', 'stars' => 2]);
});

it('filtert Ereignisse, wiederholt bei Fehlern und protokolliert die Zustellung', function () {
    $this->webhook->update(['events' => ['level_up']]);
    $todo = makeTask($this->child, ['title' => 'Hausaufgaben']);
    $this->actingAs($this->child)->post('/assignments/'.assignmentOf($todo, $this->child)->id.'/score/up');
    expect(sentWebhooks())->toBeEmpty();

    $this->webhook->update(['url' => 'https://ha-down.example/hook']);
    Http::fake(['ha-down.example/*' => Http::response('kaputt', 500)]);
    expect(fn () => (new SendHomeAssistantWebhook($this->webhook->id, ['id' => 'x', 'event' => 'level_up', 'data' => []]))->handle())
        ->toThrow(RuntimeException::class);

    expect($this->webhook->refresh()->failures)->toBe(1)
        ->and($this->webhook->last_error)->toBe('HTTP 500')
        ->and(HaAuditEntry::where('direction', 'out')->first()->action)->toBe('webhook:level_up');
});

it('richtet den Webhook im Web ein – Secret nur einmal sichtbar, nur für Eltern', function () {
    $this->webhook->delete();

    $this->actingAs($this->child)->put('/family/ha-webhook', ['url' => 'https://ha.example/x'])->assertForbidden();

    $this->actingAs($this->parent)->put('/family/ha-webhook', ['url' => 'https://ha.example/x', 'events' => ['reward_redeemed']])
        ->assertSessionHas('haWebhookSecret');
    $webhook = HaWebhook::where('family_id', $this->family->id)->first();
    expect($webhook->events)->toBe(['reward_redeemed'])
        ->and(strlen($webhook->secret))->toBe(40)
        ->and($webhook->getRawOriginal('secret'))->not->toBe($webhook->secret);

    $this->actingAs($this->parent)->post('/family/ha-webhook/test')->assertSessionHas('success');
    expect(collect(sentWebhooks())->pluck('event'))->toContain('ping');

    $this->actingAs($this->parent)->get('/family/settings')->assertInertia(fn ($page) => $page
        ->where('haWebhook.url', 'https://ha.example/x')
        ->missing('haWebhook.secret'));
});

it('bietet die HA-Dienste create_task, complete_task, approve und grant_stars an', function () {
    Sanctum::actingAs($this->parent, ['ha']);

    $created = $this->postJson('/api/v1/ha/tasks', ['title' => 'Wäsche aufhängen', 'member_id' => $this->child->id, 'star_reward' => 2])
        ->assertCreated();
    $this->postJson('/api/v1/ha/tasks/'.$created->json('id').'/complete')->assertOk()->assertJsonPath('status', 'auto');
    expect($this->child->stats()->first()->stars)->toBe(2);

    // Ohne „ha:approve“ keine Eltern-Funktionen
    $this->getJson('/api/v1/ha/approvals')->assertForbidden();
    $this->postJson("/api/v1/ha/stars/{$this->child->id}", ['stars' => 3])->assertForbidden();

    Sanctum::actingAs($this->parent, ['ha', 'ha:approve']);
    $this->postJson("/api/v1/ha/stars/{$this->child->id}", ['stars' => 3, 'reason' => 'Tisch gedeckt'])->assertOk()->assertJsonPath('stars', 5);

    $pending = makeTask($this->parent, [
        'scope' => 'family', 'type' => 'todo', 'title' => 'Zimmer', 'assignment_mode' => 'assigned',
        'assignees' => [$this->child->id], 'approval_mode' => 'required', 'star_reward' => 1,
    ]);
    $this->actingAs($this->child)->post('/assignments/'.assignmentOf($pending, $this->child)->id.'/score/up');
    $completion = TaskCompletion::where('task_id', $pending->id)->first();

    Sanctum::actingAs($this->parent, ['ha', 'ha:approve']);
    $this->getJson('/api/v1/ha/approvals')->assertOk()->assertJsonPath('completions.0.task', 'Zimmer');
    $this->postJson("/api/v1/ha/completions/{$completion->id}/approve")->assertOk()->assertJsonPath('status', 'approved');

    $log = HaAuditEntry::where('direction', 'in')->pluck('action');
    expect($log)->toContain('POST ha/tasks', 'POST ha/stars/'.$this->child->id)
        ->and($log)->not->toContain('GET ha/approvals');
});

it('lässt fremde Familien bei den HA-Diensten außen vor', function () {
    [, $otherParent, $otherChild] = makeFamily();
    $foreign = makeTask($otherParent, ['scope' => 'family', 'type' => 'todo', 'title' => 'Fremd', 'assignment_mode' => 'assigned', 'assignees' => [$otherChild->id], 'approval_mode' => 'none']);

    Sanctum::actingAs($this->parent, ['ha', 'ha:approve']);
    $this->postJson("/api/v1/ha/tasks/{$foreign->id}/complete")->assertNotFound();
    $this->postJson("/api/v1/ha/stars/{$otherChild->id}", ['stars' => 1])->assertNotFound();
    $this->postJson('/api/v1/ha/tasks', ['title' => 'X', 'member_id' => $otherChild->id])->assertUnprocessable();
});
