<?php

use App\Domain\Gameplay\RunDayCron;
use App\Domain\Gameplay\ScoreTask;
use App\Models\DayLog;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-19 10:00', 'Europe/Berlin'));
    $this->user = User::factory()->create(['timezone' => 'Europe/Berlin', 'day_start' => 4, 'birth_year' => 1985]);
    $this->cron = app(RunDayCron::class);
    ($this->cron)($this->user); // erster Lauf setzt nur das Datum
});

it('verursacht Schaden für verpasste Dailies und pausiert die Streak (Konzept §11.3)', function () {
    $task = makeTask($this->user, ['type' => 'daily', 'difficulty' => 1]);
    assignmentOf($task, $this->user)->update(['streak' => 5]);

    $this->travelTo(CarbonImmutable::parse('2026-10-20 08:00', 'Europe/Berlin'));
    $log = ($this->cron)($this->user->refresh());

    $stats = $this->user->stats()->first();
    expect($log->dailies_due)->toBe(1)
        ->and($log->dailies_done)->toBe(0)
        ->and($stats->hp)->toBe(48.0)
        ->and(assignmentOf($task, $this->user)->streak)->toBe(5)
        ->and(assignmentOf($task, $this->user)->streak_paused_at)->not->toBeNull()
        ->and(assignmentOf($task, $this->user)->value)->toBe(-1.0);
});

it('macht keinen Schaden für erledigte Dailies und setzt sie zurück', function () {
    $task = makeTask($this->user, ['type' => 'daily']);
    app(ScoreTask::class)(assignmentOf($task, $this->user), 'up');

    $this->travelTo(CarbonImmutable::parse('2026-10-20 08:00', 'Europe/Berlin'));
    ($this->cron)($this->user->refresh());

    expect($this->user->stats()->first()->hp)->toBe(50.0)
        ->and(assignmentOf($task, $this->user)->completed)->toBeFalse()
        ->and(assignmentOf($task, $this->user)->streak)->toBe(1);
});

it('respektiert den Tagesbeginn (vor 4 Uhr ist noch gestern)', function () {
    makeTask($this->user, ['type' => 'daily']);

    $this->travelTo(CarbonImmutable::parse('2026-10-20 03:30', 'Europe/Berlin'));

    expect(RunDayCron::isPending($this->user->refresh()))->toBeFalse()
        ->and(($this->cron)($this->user))->toBeNull();
});

it('läuft pro Tag nur einmal', function () {
    makeTask($this->user, ['type' => 'daily']);
    $this->travelTo(CarbonImmutable::parse('2026-10-20 08:00', 'Europe/Berlin'));

    ($this->cron)($this->user->refresh());
    ($this->cron)($this->user->refresh());

    expect(DayLog::count())->toBe(1)
        ->and($this->user->stats()->first()->hp)->toBe(48.0);
});

it('sieht neue Dailies am selben Tag als fällig an, auch abends in Berlin', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-19 23:30', 'Europe/Berlin'));
    $task = makeTask($this->user, ['type' => 'daily']);

    expect($task->isDueOn($this->user->gameDate()))->toBeTrue();
});

it('ignoriert Dailies, die an dem Wochentag nicht fällig waren', function () {
    // 19.10.2026 ist ein Montag
    makeTask($this->user, ['type' => 'daily', 'repeat' => ['type' => 'weekly', 'days' => [2, 3]]]);

    $this->travelTo(CarbonImmutable::parse('2026-10-20 08:00', 'Europe/Berlin'));
    $log = ($this->cron)($this->user->refresh());

    expect($log->dailies_due)->toBe(0)->and($this->user->stats()->first()->hp)->toBe(50.0);
});

it('wertet im Dialog nachgetragene Aufgaben für gestern', function () {
    $task = makeTask($this->user, ['type' => 'daily']);
    $this->travelTo(CarbonImmutable::parse('2026-10-20 08:00', 'Europe/Berlin'));

    $open = RunDayCron::openFromLastDay($this->user->refresh());
    $log = ($this->cron)($this->user, null, [$open->first()->id]);

    expect($open)->toHaveCount(1)
        ->and($log->dailies_done)->toBe(1)
        ->and($this->user->stats()->first()->hp)->toBe(50.0)
        ->and(assignmentOf($task, $this->user)->streak)->toBe(1);
});

it('macht im Gasthaus-Modus keinen Schaden', function () {
    makeTask($this->user, ['type' => 'daily']);
    $this->user->stats()->update(['sleeping' => true]);

    $this->travelTo(CarbonImmutable::parse('2026-10-20 08:00', 'Europe/Berlin'));
    ($this->cron)($this->user->refresh());

    expect($this->user->stats()->first()->hp)->toBe(50.0);
});

it('nutzt einen Streak-Schutz statt die Streak zu verlieren', function () {
    $task = makeTask($this->user, ['type' => 'daily']);
    assignmentOf($task, $this->user)->update(['streak' => 7]);
    $this->user->stats()->update(['streak_shields' => 1]);

    $this->travelTo(CarbonImmutable::parse('2026-10-20 08:00', 'Europe/Berlin'));
    ($this->cron)($this->user->refresh());

    expect(assignmentOf($task, $this->user)->streak)->toBe(7)
        ->and($this->user->stats()->first()->streak_shields)->toBe(0);
});
