<?php

use App\Domain\Events\EventCalendar;
use App\Domain\Events\EventEngine;
use App\Domain\Events\EventTrigger;
use App\Domain\Events\FunEventService;
use App\Domain\Gameplay\ScoreTask;
use App\Models\FamilyEvent;
use App\Models\FamilyEventSetting;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    [$this->family, $this->parent, $this->child] = makeFamily();
    $this->trigger = app(EventTrigger::class);
    $this->engine = app(EventEngine::class);
});

it('rechnet Ostern, den 1. Advent und Erntedank richtig', function () {
    expect(EventCalendar::easter(2026)->toDateString())->toBe('2026-04-05')
        ->and(EventCalendar::easter(2027)->toDateString())->toBe('2027-03-28')
        ->and(EventCalendar::easter(2028)->toDateString())->toBe('2028-04-16')
        ->and(EventCalendar::firstAdvent(2026)->toDateString())->toBe('2026-11-29')
        ->and(EventCalendar::firstAdvent(2027)->toDateString())->toBe('2027-11-28')
        ->and(EventCalendar::harvestThanksgiving(2026)->toDateString())->toBe('2026-10-04');
});

it('startet ein Event, verhindert Doppelte und hält die Abklingzeit ein', function () {
    $event = $this->trigger->consider($this->family->id, 'heatwave', ['source' => 'ha']);
    expect($event->status)->toBe('active')
        ->and($event->progress['goal'])->toEqual(8 * 2 * 3) // Punkte je Mitglied und Tag × 2 Mitglieder × 3 Tage
        ->and($this->trigger->consider($this->family->id, 'heatwave', ['source' => 'ha']))->toBeNull();

    $this->engine->finish($event);
    expect($this->trigger->consider($this->family->id, 'heatwave', ['source' => 'ha']))->toBeNull()
        ->and(FamilyEvent::count())->toBe(1);
});

it('lässt abgeschaltete Events aus', function () {
    FamilyEventSetting::create(['family_id' => $this->family->id, 'disabled_events' => ['heatwave']]);

    expect($this->trigger->consider($this->family->id, 'heatwave', ['source' => 'ha']))->toBeNull()
        ->and(FamilyEvent::count())->toBe(0);
});

it('stellt ein drittes Event in die Warteschlange und startet es, sobald Platz ist', function () {
    $heat = $this->trigger->consider($this->family->id, 'heatwave', ['manual' => true]);
    $this->trigger->consider($this->family->id, 'golden_week', ['manual' => true]);

    $party = $this->trigger->consider($this->family->id, 'welcome_party', ['manual' => true, 'subject_user_id' => $this->child->id]);
    expect($party->status)->toBe('scheduled')->and($party->queued_until)->not->toBeNull();

    $this->engine->finish($heat);
    $this->engine->tick();
    expect($party->refresh()->status)->toBe('active');
});

it('lässt ein wartendes Event nach drei Tagen verfallen', function () {
    $this->trigger->consider($this->family->id, 'spring_awakening', ['manual' => true]);
    $this->trigger->consider($this->family->id, 'golden_week', ['manual' => true]);
    $this->trigger->consider($this->family->id, 'house_warming', ['manual' => true]);
    $party = $this->trigger->consider($this->family->id, 'welcome_party', ['manual' => true]);
    expect($party->status)->toBe('scheduled');

    $this->travel(4)->days();
    $this->engine->tick();
    expect($party->refresh()->status)->toBe('skipped');
});

it('zahlt jede Stufe genau einmal an alle aus', function () {
    $event = $this->trigger->consider($this->family->id, 'heatwave', ['manual' => true]);
    $goal = $event->progress['goal'];

    $first = $this->engine->addPoints($event, $this->child, $goal * 0.3);
    $again = $this->engine->addPoints($event, $this->child, 1);

    expect($first)->toHaveCount(1)
        ->and($again)->toBe([])
        ->and($event->refresh()->progress['tiers'])->toBe([25])
        ->and(goldWithoutAchievements($this->parent))->toBe(5.0)
        ->and(goldWithoutAchievements($this->child))->toBe(5.0);
});

it('gibt Event-Punkte für Aufgaben und nimmt sie beim Rückgängigmachen zurück', function () {
    $event = $this->trigger->consider($this->family->id, 'heatwave', ['manual' => true]);
    $task = makeTask($this->parent, ['type' => 'daily', 'difficulty' => 1]);
    $score = app(ScoreTask::class);

    $score(assignmentOf($task, $this->parent), 'up');
    $points = $event->refresh()->progress['points'];
    expect($points)->toBeGreaterThan(0);

    $score(assignmentOf($task, $this->parent), 'down');
    expect($event->refresh()->progress['points'])->toEqual(0);
});

it('zeigt laufende Events in der API und im Board', function () {
    $this->trigger->consider($this->family->id, 'heatwave', ['manual' => true]);
    Sanctum::actingAs($this->child, ['app']);

    $this->getJson('/api/v1/events')->assertOk();
    $board = $this->getJson('/api/v1/board')->assertOk()->json();
    expect($board)->toHaveKeys(['events', 'fun_events', 'features', 'scene_background'])
        ->and(collect($board['events'])->toJson())->toContain('heatwave');
});

it('lässt Eltern Events von Hand starten, Kinder nicht', function () {
    Sanctum::actingAs($this->child, ['app']);
    $this->postJson('/api/v1/events/start', ['key' => 'heatwave'])->assertStatus(422);

    Sanctum::actingAs($this->parent, ['app']);
    $this->postJson('/api/v1/events/start', ['key' => 'heatwave'])->assertCreated();
    expect(EventEngine::isRunning($this->family->id, 'heatwave'))->toBeTrue();
});

it('würfelt Zufallsereignisse deterministisch', function () {
    expect(FunEventService::fraction('abc'))->toBe(FunEventService::fraction('abc'))
        ->and(FunEventService::fraction('abc'))->toBeGreaterThanOrEqual(0)->toBeLessThan(1);
});

it('schickt Personen ins Gasthaus und holt sie zurück', function () {
    Sanctum::actingAs($this->child, ['app']);

    $this->postJson('/api/v1/me/inn')->assertOk()->assertJsonPath('sleeping', true);
    $this->deleteJson('/api/v1/me/inn')->assertOk()->assertJsonPath('sleeping', false);
});
