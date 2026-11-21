<?php

use App\Domain\Gameplay\GameException;
use App\Domain\Gameplay\ScoreTask;
use App\Domain\Goals\GoalService;
use App\Domain\Support\SupportService;
use App\Enums\FamilyRole;
use App\Models\FeedEntry;
use App\Models\Goal;
use App\Models\GoalWeek;
use App\Models\LowEnergyDay;
use App\Models\RecoveryQuest;
use App\Models\TaskCompletion;
use App\Models\UserBadge;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    [$this->family, $this->parent, $this->child] = makeFamily();
    $this->goals = app(GoalService::class);
    // Montag, 2. November 2026, 10 Uhr Berlin
    $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00', 'Europe/Berlin'));
});

/** Erledigung an einem bestimmten Tag (Berliner Zeit) direkt eintragen. */
function completedOn($assignment, string $day, string $status = 'none', string $time = '12:00'): TaskCompletion
{
    return TaskCompletion::forceCreate([
        'task_id' => $assignment->task_id, 'assignment_id' => $assignment->id, 'user_id' => $assignment->user_id, 'direction' => 'up',
        'value_before' => 0, 'delta' => 1, 'xp' => 1, 'gold' => 1, 'hp' => 0, 'stars' => 0, 'status' => $status, 'source' => 'web',
        'created_at' => CarbonImmutable::parse("{$day} {$time}", 'Europe/Berlin')->utc(), 'updated_at' => now(),
    ]);
}

it('verlangt ein Warum und erlaubt höchstens drei aktive Ziele', function () {
    $data = ['type' => 'count', 'title' => '10 Bücher', 'target' => 10];

    expect(fn () => $this->goals->create($this->child, $this->child, $data + ['why' => '']))->toThrow(GameException::class, 'Warum');

    foreach (range(1, 3) as $i) {
        $this->goals->create($this->child, $this->child, $data + ['why' => 'weil Lesen Spaß macht']);
    }

    expect(fn () => $this->goals->create($this->child, $this->child, $data + ['why' => 'noch eins']))->toThrow(GameException::class, 'Drei Ziele')
        ->and(GoalService::activeCount($this->child))->toBe(3);
});

it('erlaubt private Ziele erst ab 13 und zeigt sie anderen nur als Anzahl', function () {
    $teen = joinFamily($this->family, FamilyRole::Child, ['name' => 'Ben', 'birth_year' => (int) now()->year - 14, 'timezone' => 'Europe/Berlin']);

    expect(fn () => $this->goals->create($this->child, $this->child, ['type' => 'count', 'title' => 'x', 'target' => 3, 'why' => 'y', 'is_private' => true]))
        ->toThrow(GameException::class, 'ab 13');

    $this->goals->create($teen, $teen, ['type' => 'count', 'title' => 'Geheim', 'target' => 3, 'why' => 'meins', 'is_private' => true]);

    $seenByParent = $this->goals->forUser($teen->refresh(), $this->parent);
    expect($seenByParent['goals'])->toBe([])->and($seenByParent['private_count'])->toBe(1)
        ->and($this->goals->forUser($teen, $teen)['goals'][0]['title'])->toBe('Geheim')
        ->and(FeedEntry::where('type', 'goal_started')->count())->toBe(0);
});

it('zählt Wochenziele im Wochenfenster, Energiespar-Tage verkleinern das Ziel', function () {
    $daily = makeTask($this->child, ['type' => 'daily', 'title' => 'Zähne putzen', 'repeat' => ['type' => 'daily']]);
    $goal = $this->goals->create($this->child, $this->child, ['type' => 'weekly', 'title' => 'Zähne putzen', 'target' => 5, 'why' => 'gesunde Zähne', 'task_ids' => [$daily->id]]);
    $a = assignmentOf($daily, $this->child);

    // Vorwoche zählt nicht, Sonntag 23:59 der Vorwoche auch nicht
    completedOn($a, '2026-10-30');
    completedOn($a, '2026-11-01', 'none', '23:59');
    completedOn($a, '2026-11-02');
    completedOn($a, '2026-11-02', 'none', '18:00'); // doppelt am selben Tag zählt einmal
    completedOn($a, '2026-11-03', 'rejected');

    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00', 'Europe/Berlin'));
    expect($this->goals->progress($goal->refresh()))->toMatchArray(['done' => 1, 'target' => 5, 'skipped' => 0]);

    LowEnergyDay::create(['user_id' => $this->child->id, 'date' => '2026-11-04', 'set_by' => $this->child->id]);
    expect(SupportService::isLowEnergy($this->child, CarbonImmutable::parse('2026-11-04')))->toBeTrue()
        ->and($this->goals->progress($goal->refresh()))->toMatchArray(['done' => 1, 'target' => 4, 'skipped' => 1]);
});

it('wertet Wochenziele Sonntagabend aus, belohnt einmal und hält die Serie', function () {
    $daily = makeTask($this->child, ['type' => 'daily', 'title' => 'Lesen', 'repeat' => ['type' => 'daily']]);
    $goal = $this->goals->create($this->child, $this->child, ['type' => 'weekly', 'title' => 'Lesen', 'target' => 3, 'why' => 'Geschichten', 'task_ids' => [$daily->id]]);
    $a = assignmentOf($daily, $this->child);
    foreach (['2026-11-02', '2026-11-04', '2026-11-06'] as $day) {
        completedOn($a, $day);
    }
    // Ein verpasster Tag hat die Serie pausiert
    $a->forceFill(['streak' => 5, 'streak_paused_at' => now()])->save();
    RecoveryQuest::create(['user_id' => $this->child->id, 'assignment_id' => $a->id, 'needed' => 2, 'streak_to_restore' => 5, 'expires_on' => '2026-11-10']);

    $this->travelTo(CarbonImmutable::parse('2026-11-08 23:00', 'Europe/Berlin'));
    expect($this->goals->evaluateWeeks($this->child))->toBe(0); // vor 23:30 nur die (leere) Vorwoche – Ziel gab es da noch nicht

    $this->travelTo(CarbonImmutable::parse('2026-11-08 23:35', 'Europe/Berlin'));
    $gold = $this->child->stats()->first()->gold;
    expect($this->goals->evaluateWeeks($this->child))->toBe(1)
        ->and($this->goals->evaluateWeeks($this->child))->toBe(0); // genau einmal

    $a->refresh();
    expect(GoalWeek::first())->toMatchArray(['done' => 3, 'target' => 3, 'reached' => true])
        ->and($goal->refresh()->weeks_reached)->toBe(1)
        ->and($this->child->stats()->first()->gold)->toBeGreaterThan($gold)
        ->and($a->streak)->toBe(5)->and($a->streak_paused_at)->toBeNull()
        ->and(UserBadge::where('user_id', $this->child->id)->where('kind', 'goal')->count())->toBe(1);

    // Montag nach Mitternacht: Nachholen der Vorwoche schreibt nichts doppelt
    $this->travelTo(CarbonImmutable::parse('2026-11-09 08:00', 'Europe/Berlin'));
    expect($this->goals->evaluateWeeks($this->child))->toBe(0);
});

it('Meilensteine bei Kindern bestätigen die Eltern, Belohnung genau einmal', function () {
    $goal = $this->goals->create($this->child, $this->child, ['type' => 'milestone', 'title' => 'Fahrrad', 'why' => 'allein zur Schule',
        'milestones' => ['Laufrad', '20 Meter', 'bis zur Ecke']]);
    [$first, $second, $third] = $goal->milestones->all();
    $gold = $this->child->stats()->first()->gold;

    expect($this->goals->completeMilestone($this->child, $goal, $first))->toBe('requested')
        ->and($first->refresh()->done_at)->toBeNull()
        ->and(GoalService::pendingConfirmations($this->parent))->toHaveCount(1)
        ->and($this->child->stats()->first()->gold)->toBe($gold);

    expect($this->goals->completeMilestone($this->parent, $goal, $first))->toBe('done')
        ->and(fn () => $this->goals->completeMilestone($this->parent, $goal, $first))->toThrow(GameException::class, 'schon');
    $afterOne = $this->child->stats()->first()->gold;
    expect($afterOne)->toBeGreaterThan($gold);

    $this->goals->completeMilestone($this->parent, $goal, $second);
    $this->goals->completeMilestone($this->parent, $goal, $third);

    expect($goal->refresh()->status)->toBe('reached')
        ->and(UserBadge::where('user_id', $this->child->id)->where('key', "goal:{$goal->id}")->count())->toBe(1)
        ->and(FeedEntry::where('type', 'goal_reached')->count())->toBe(1);
});

it('Zähl-Ziele erreichen sich über verknüpfte Aufgaben und Handzählung, Sterne der Eltern einmal', function () {
    $todo = makeTask($this->child, ['type' => 'habit', 'title' => 'Buch gelesen', 'up' => true, 'down' => false]);
    $goal = $this->goals->create($this->parent, $this->child, ['type' => 'count', 'title' => '3 Bücher', 'target' => 3, 'why' => 'Abenteuer',
        'task_ids' => [$todo->id], 'reward_stars' => 10]);
    $stars = $this->child->stats()->first()->stars;

    app(ScoreTask::class)(assignmentOf($todo, $this->child), 'up');
    $this->goals->addCount($this->child, $goal, 1);
    expect($goal->refresh()->status)->toBe('active');

    app(ScoreTask::class)(assignmentOf($todo, $this->child), 'up');
    expect($goal->refresh()->status)->toBe('reached')
        ->and($this->child->stats()->first()->stars)->toBe($stars + 10);

    // Weiterzählen nach dem Ziel bringt nichts mehr
    expect(fn () => $this->goals->addCount($this->child, $goal->refresh(), 1))->toThrow(GameException::class);
});

it('fragt bei verpasstem Enddatum statt zu strafen; verlängern macht es wieder aktiv', function () {
    $goal = $this->goals->create($this->child, $this->child, ['type' => 'count', 'title' => 'Sparen', 'target' => 20, 'why' => 'Lego', 'ends_on' => '2026-11-05']);
    $hp = $this->child->stats()->first()->hp;

    $this->travelTo(CarbonImmutable::parse('2026-11-07 10:00', 'Europe/Berlin'));
    expect($this->goals->expireDue($this->child))->toBe(1)
        ->and($goal->refresh()->status)->toBe('expired')
        ->and($this->child->stats()->first()->hp)->toBe($hp)
        ->and($this->goals->boardSummary($this->child)['expired'])->toHaveCount(1);

    $this->goals->update($this->child, $goal->load('user'), ['ends_on' => '2026-11-30']);
    expect($goal->refresh()->status)->toBe('active');

    $this->goals->release($this->child, $goal->load('user'));
    expect($goal->refresh()->status)->toBe('released');
});

it('Unterstützer helfen bei fremden, nicht privaten Zielen', function () {
    $goal = $this->goals->create($this->child, $this->child, ['type' => 'count', 'title' => 'Schwimmen', 'target' => 5, 'why' => 'Seepferdchen']);

    expect(fn () => $this->goals->support($this->child, $goal->load('user')))->toThrow(GameException::class);
    $this->goals->support($this->parent, $goal->load('user'));

    $presented = $this->goals->forUser($this->child, $this->parent)['goals'][0];
    expect($presented['is_supporter'])->toBeTrue()->and($presented['supporters'][0]['name'])->toBe($this->parent->name);
});

it('Ziele über Web und API', function () {
    $daily = makeTask($this->child, ['type' => 'daily', 'title' => 'Üben', 'repeat' => ['type' => 'daily']]);

    $this->actingAs($this->child)->post('/goals', ['template_key' => 'bike_no_training_wheels', 'why' => 'mit den anderen fahren'])->assertSessionHasNoErrors();
    $this->get('/goals')->assertInertia(fn ($page) => $page->component('Goals')
        ->where('mine.goals.0.title', 'Fahrrad fahren ohne Stützräder')
        ->where('mine.goals.0.milestones.0.title', 'Eine Runde mit dem Laufrad')
        ->has('templates')->has('family_goals'));

    Sanctum::actingAs($this->child, ['app']);
    $this->postJson('/api/v1/goals', ['type' => 'weekly', 'title' => 'Üben', 'target' => 4, 'why' => 'Konzert', 'task_ids' => [$daily->id]])
        ->assertCreated()->assertJsonPath('progress.target', 4);
    $this->getJson('/api/v1/goals')->assertOk()->assertJsonPath('active_count', 2)->assertJsonCount(2, 'goals');
    $this->getJson('/api/v1/board')->assertJsonCount(2, 'goals.active');

    $goal = Goal::where('type', 'milestone')->first();
    $this->postJson("/api/v1/goals/{$goal->id}/milestones/{$goal->milestones->first()->id}/done")->assertOk()->assertJsonPath('result', 'requested');
});
