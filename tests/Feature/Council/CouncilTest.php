<?php

use App\Domain\Council\CouncilService;
use App\Domain\Council\ProposalService;
use App\Domain\Gameplay\GameException;
use App\Domain\Quests\QuestService;
use App\Jobs\SendPushNotification;
use App\Models\DeviceToken;
use App\Models\FamilyCouncil;
use App\Models\FeedEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    // Sonntag, 25.10.2026
    $this->travelTo(CarbonImmutable::parse('2026-10-25 17:30', 'Europe/Berlin'));
    [$this->family, $this->parent, $this->child] = makeFamily();
    $this->councils = app(CouncilService::class);
});

it('nächster Termin: Standard Sonntag 18 Uhr, änderbar in den Familieneinstellungen', function () {
    expect(CouncilService::nextAt($this->family)->format('Y-m-d H:i'))->toBe('2026-10-25 18:00');

    $this->actingAs($this->parent)->put('/family/council-settings', ['weekday' => 5, 'time' => '19:30', 'enabled' => true, 'free_text' => true])
        ->assertSessionHasNoErrors();
    $family = $this->family->refresh();
    expect(CouncilService::nextAt($family)->format('D H:i'))->toBe('Fri 19:30')
        ->and(CouncilService::settings($family)['free_text'])->toBeTrue();

    $this->actingAs($this->child)->put('/family/council-settings', ['weekday' => 1, 'time' => '10:00'])->assertForbidden();
});

it('Erinnerung eine Stunde vorher, genau einmal pro Termin', function () {
    Queue::fake();
    DeviceToken::create(['user_id' => $this->child->id, 'token' => 'kind']);
    $this->travelTo(CarbonImmutable::parse('2026-10-25 16:30', 'Europe/Berlin'));
    expect($this->councils->sendReminders())->toBe(0);

    $this->travelTo(CarbonImmutable::parse('2026-10-25 17:02', 'Europe/Berlin'));
    $this->artisan('heldenhaus:council-tick')->assertSuccessful();
    expect($this->councils->sendReminders())->toBe(0);

    Queue::assertPushed(SendPushNotification::class, fn ($job) => $job->type === 'family_council');
    Queue::assertPushed(SendPushNotification::class, 1);

    // Abgeschaltet: keine Erinnerung
    $this->family->refresh()->update(['settings' => ['council' => ['enabled' => false]]]);
    $this->travelTo(CarbonImmutable::parse('2026-11-01 17:10', 'Europe/Berlin'));
    expect($this->councils->sendReminders())->toBe(0);
});

it('geführter Ablauf: Rückblick ohne Rangliste, Danke im Feed, Entscheidungen, Wünsche, Ergebnis im Feed', function () {
    $quest = app(QuestService::class)->start($this->parent, 'dust_monster', 'family');
    $quest->update(['status' => 'completed', 'completed_at' => now()]);
    $proposal = app(ProposalService::class)->propose($this->child, ['kind' => 'reward', 'template_key' => 'cinema']);

    $this->actingAs($this->parent)->post('/family/council', ['action' => 'start'])->assertSessionHasNoErrors();
    $council = CouncilService::running($this->family->id);
    expect($council->recap['quests'][0]['name'])->toBe($quest->name)
        ->and(json_encode($council->recap))->not->toContain('Lea');

    // Danke-Runde am Familiengerät: Eltern tragen für das Kind ein
    $this->post('/family/council', ['action' => 'thank', 'from_id' => $this->child->id, 'to_id' => $this->parent->id, 'reason' => 'fürs Vorlesen'])
        ->assertSessionHasNoErrors();
    $this->post('/family/council', ['action' => 'thank', 'from_id' => $this->child->id, 'to_id' => $this->parent->id, 'reason' => 'fürs Kochen'])
        ->assertSessionHasErrors('game');
    expect(FeedEntry::where('type', 'council_thanks')->value('text'))->toBe("Lea dankt {$this->parent->name} fürs Vorlesen");

    $this->post("/proposals/{$proposal->id}/accept")->assertSessionHasNoErrors();
    $this->post('/family/council', ['action' => 'wish', 'member_id' => $this->child->id, 'text' => 'Einen Ausflug machen', 'kind' => 'goal'])
        ->assertSessionHasNoErrors();
    $this->post('/family/council', ['action' => 'step', 'step' => 5])->assertSessionHasNoErrors();
    $this->post('/family/council', ['action' => 'finish'])->assertSessionHasNoErrors();

    $council->refresh();
    expect($council->status)->toBe('done')
        ->and($council->decisions[0]['proposal_id'])->toBe($proposal->id)
        ->and(FeedEntry::where('type', 'council_done')->value('text'))->toStartWith('Familienrat vom 25.10.:')
        ->and(FeedEntry::where('type', 'council_done')->value('text'))->toContain('1× Danke');

    $wishes = $this->councils->wishes($this->family, '2026-10-01');
    expect($wishes)->toHaveCount(1)
        ->and($wishes->first())->toMatchArray(['user_id' => $this->child->id, 'kind' => 'goal', 'text' => 'Einen Ausflug machen']);
    expect($this->councils->wishes($this->family, '2026-10-26'))->toHaveCount(0);
});

it('Kinder sprechen nur für sich, unter 13 mit Vorlagen; Eltern eröffnen und schließen', function () {
    expect(fn () => $this->councils->start($this->child))->toThrow(GameException::class);
    $council = $this->councils->start($this->parent);

    expect(fn () => $this->councils->wish($this->child, $council, $this->parent, 'Mehr draußen sein'))->toThrow(GameException::class)
        ->and(fn () => $this->councils->wish($this->child, $council, $this->child, 'Ein Pony'))->toThrow(GameException::class, 'Liste')
        ->and(fn () => $this->councils->finish($this->child, $council))->toThrow(GameException::class);

    $this->councils->wish($this->child, $council, $this->child, 'Mehr draußen sein');
    expect($council->refresh()->wishes)->toHaveCount(1);
});

it('API: GET|POST family-council', function () {
    Sanctum::actingAs($this->parent, ['app']);
    $this->getJson('/api/v1/family-council')->assertOk()
        ->assertJsonPath('council', null)->assertJsonPath('settings.weekday', 0)->assertJsonPath('settings.time', '18:00');
    $this->postJson('/api/v1/family-council', ['action' => 'start'])->assertOk()->assertJsonPath('council.step', 1);
    $this->postJson('/api/v1/family-council', ['action' => 'open_vote'])->assertOk()->assertJsonPath('vote.status', 'open');
    expect(FamilyCouncil::count())->toBe(1);

    Sanctum::actingAs($this->child, ['app']);
    $this->postJson('/api/v1/family-council', ['action' => 'finish'])->assertStatus(422);
});

it('ausgelassene Räte haben keine Folgen', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00', 'Europe/Berlin'));
    $this->artisan('heldenhaus:council-tick')->assertSuccessful();

    expect(FamilyCouncil::count())->toBe(0)->and(FeedEntry::where('type', 'like', 'council%')->count())->toBe(0);
    $this->actingAs($this->child)->get('/family/council')->assertOk()->assertInertia(fn ($p) => $p->component('family/Council')->where('council', null));
});
