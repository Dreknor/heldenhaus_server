<?php

use App\Domain\Social\AllianceService;
use App\Domain\Social\ChallengeService;
use App\Enums\FamilyRole;
use App\Models\Challenge;
use App\Models\FamilyAlliance;
use App\Models\Message;
use App\Models\Task;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    [$this->family, $this->parent, $this->child] = makeFamily();
    [$this->otherFamily, $this->otherParent, $this->otherChild] = makeFamily();
});

function ally($test): FamilyAlliance
{
    $invite = app(AllianceService::class)->invite($test->parent);

    return app(AllianceService::class)->join($test->otherParent, $invite->code);
}

function readingChallenge($parent, array $overrides = []): Challenge
{
    return app(ChallengeService::class)->create($parent, array_merge([
        'title' => '7 Tage lesen', 'icon' => '📚', 'tasks' => [['title' => '15 Minuten lesen', 'type' => 'daily', 'category' => 'school']],
        'target' => 2, 'reward_gold' => 20, 'starts_on' => now()->toDateString(), 'ends_on' => now()->addDays(6)->toDateString(),
        'scope' => 'alliance', 'leaderboard' => true,
    ], $overrides));
}

it('verbündet zwei Familien nur mit Zustimmung von Eltern beider Seiten', function () {
    $this->actingAs($this->child)->post('/alliances')->assertSessionHasErrors('game');

    $this->actingAs($this->parent)->post('/alliances')->assertSessionHas('success');
    $code = FamilyAlliance::first()->code;

    $this->actingAs($this->otherChild)->post('/alliances/join', ['code' => $code])->assertSessionHasErrors('game');
    $this->actingAs($this->parent)->post('/alliances/join', ['code' => $code])->assertSessionHasErrors('game'); // eigene Familie

    Sanctum::actingAs($this->otherParent, ['app']);
    $this->postJson('/api/v1/alliances/join', ['code' => strtolower($code)])->assertOk()->assertJsonPath('status', 'active');

    expect(FamilyAlliance::alliedFamilyIds($this->family->id))->toBe([$this->otherFamily->id])
        ->and($this->getJson('/api/v1/social')->json('alliances.active.0.family'))->toBe('Familie Test')
        ->and($this->getJson('/api/v1/social')->json('alliances.active.0.family_id'))->toBe($this->family->id);

    // Code ist verbraucht, ein Elternteil kann das Bündnis beenden
    $this->postJson('/api/v1/alliances/join', ['code' => $code])->assertUnprocessable();
    $this->deleteJson('/api/v1/alliances/'.FamilyAlliance::first()->id)->assertOk();
    expect(FamilyAlliance::alliedFamilyIds($this->family->id))->toBe([]);
});

it('lässt Kinder und Verbündete bei Challenges mitmachen und belohnt das Ziel einmal', function () {
    ally($this);
    $challenge = readingChallenge($this->parent);

    Sanctum::actingAs($this->otherChild, ['app']);
    $this->getJson('/api/v1/social')->assertOk()->assertJsonPath('challenges.0.title', '7 Tage lesen');
    $this->postJson("/api/v1/challenges/{$challenge->id}/join")->assertOk()->assertJsonPath('joined', true);
    $this->postJson("/api/v1/challenges/{$challenge->id}/join")->assertUnprocessable();

    $task = Task::where('challenge_id', $challenge->id)->where('owner_id', $this->otherChild->id)->firstOrFail();
    expect($task->type->value)->toBe('daily');

    $goldBefore = (float) $this->otherChild->stats()->first()->gold;
    $this->postJson('/api/v1/assignments/'.assignmentOf($task, $this->otherChild)->id.'/score/up')->assertOk();
    expect($this->getJson('/api/v1/social')->json('challenges.0.my_score'))->toBe(1);

    // nächster Tag: zweiter Abschluss erreicht das Ziel
    $this->travel(1)->day();
    $this->postJson('/api/v1/cron')->assertOk();
    $this->postJson('/api/v1/assignments/'.assignmentOf($task, $this->otherChild)->id.'/score/up')->assertOk();

    $view = $this->getJson('/api/v1/social')->json('challenges.0');
    expect($view['completed'])->toBeTrue()
        ->and($view['leaderboard'][0])->toMatchArray(['name' => 'Lea', 'score' => 2, 'me' => true])
        ->and((float) $this->otherChild->stats()->first()->gold)->toBeGreaterThan($goldBefore + 19);
});

it('zeigt Familien-Challenges nicht bei Verbündeten und schließt abgelaufene', function () {
    ally($this);
    $private = readingChallenge($this->parent, ['scope' => 'family', 'leaderboard' => false]);

    Sanctum::actingAs($this->otherChild, ['app']);
    expect($this->getJson('/api/v1/social')->json('challenges'))->toBe([]);
    $this->postJson("/api/v1/challenges/{$private->id}/join")->assertUnprocessable();

    Sanctum::actingAs($this->child, ['app']);
    $this->postJson("/api/v1/challenges/{$private->id}/join")->assertOk()->assertJsonPath('leaderboard', null);

    $this->travel(9)->days();
    expect(app(ChallengeService::class)->closeExpired())->toBe(1)
        ->and(Task::where('challenge_id', $private->id)->whereNull('archived_at')->count())->toBe(0);
});

it('Gäste machen nicht mit, Kinder legen keine Challenges an', function () {
    $guest = joinFamily($this->family, FamilyRole::Guest);
    $challenge = readingChallenge($this->parent);

    Sanctum::actingAs($guest, ['app']);
    $this->postJson("/api/v1/challenges/{$challenge->id}/join")->assertUnprocessable();

    Sanctum::actingAs($this->child, ['app']);
    $this->postJson('/api/v1/challenges', [
        'title' => 'X', 'tasks' => [['title' => 'Y', 'type' => 'habit']], 'target' => 1,
        'starts_on' => now()->toDateString(), 'ends_on' => now()->toDateString(), 'scope' => 'family',
    ])->assertUnprocessable();
});

it('filtert Freitext im Bündnis nach Alter, in der Familie dürfen alle schreiben', function () {
    $alliance = ally($this);
    $teen = joinFamily($this->otherFamily, FamilyRole::Child, ['birth_year' => now()->year - 14, 'name' => 'Tom']);

    // Familie: Freitext für alle
    Sanctum::actingAs($this->child, ['app']);
    $this->postJson('/api/v1/messages', ['kind' => 'text', 'body' => 'Ich hab den Müll rausgebracht'])->assertCreated();
    expect($this->getJson('/api/v1/messages')->json('messages.0.text'))->toBe('Ich hab den Müll rausgebracht');

    // Bündnis: Kind unter 13 nur Vorlagen und Emojis
    $this->postJson('/api/v1/messages', ['alliance' => $alliance->id, 'kind' => 'text', 'body' => 'Hallo'])->assertUnprocessable();
    $this->postJson('/api/v1/messages', ['alliance' => $alliance->id, 'kind' => 'preset', 'body' => 'well_done'])->assertCreated();
    $this->postJson('/api/v1/messages', ['alliance' => $alliance->id, 'kind' => 'emoji', 'body' => '🔥'])->assertCreated();

    Sanctum::actingAs($this->otherParent, ['app']);
    $this->postJson('/api/v1/messages', ['alliance' => $alliance->id, 'kind' => 'text', 'body' => 'Grüße an alle!'])->assertCreated();

    // Das Kind sieht den Freitext der Erwachsenen nicht
    Sanctum::actingAs($this->child, ['app']);
    $messages = collect($this->getJson("/api/v1/messages?alliance={$alliance->id}")->json('messages'));
    expect($messages->pluck('text')->all())->toBe(['Gut gemacht! 👍', '🔥', null])
        ->and($messages->last()['hidden'])->toBeTrue()
        ->and($messages->last()['family'])->toBe('Familie Test');

    // Teen erst nach Freigabe durch die Eltern
    Sanctum::actingAs($teen, ['app']);
    $this->postJson('/api/v1/messages', ['alliance' => $alliance->id, 'kind' => 'text', 'body' => 'Hi'])->assertUnprocessable();
    $this->actingAs($this->otherParent)->put("/family/members/{$teen->id}", ['name' => 'Tom', 'chat_allowed' => true]);
    Sanctum::actingAs($teen->refresh(), ['app']);
    $this->postJson('/api/v1/messages', ['alliance' => $alliance->id, 'kind' => 'text', 'body' => 'Hi'])->assertCreated();

    // Fremde Familie ohne Bündnis kommt nicht in den Kanal
    [, $strangerParent] = makeFamily();
    Sanctum::actingAs($strangerParent, ['app']);
    $this->getJson("/api/v1/messages?alliance={$alliance->id}")->assertUnprocessable();
    expect(Message::count())->toBe(5);
});

it('zeigt die Seite im Web', function () {
    ally($this);
    readingChallenge($this->parent);

    $this->actingAs($this->parent)->get('/social')->assertOk()->assertInertia(fn ($page) => $page
        ->component('family/Social')
        ->has('challenges', 1)
        ->has('alliances.active', 1));
});
