<?php

use App\Domain\Achievements\AchievementService;
use App\Domain\Gameplay\Formulas;
use App\Domain\Gameplay\RunDayCron;
use App\Domain\Gameplay\ScoreTask;
use App\Domain\Quests\QuestService;
use App\Domain\Social\FeedService;
use App\Domain\Tasks\ReviewCompletion;
use App\Models\FeedEntry;
use App\Models\UserAchievement;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-19 10:00', 'Europe/Berlin'));
    [$this->family, $this->parent, $this->child] = makeFamily();
    $this->score = app(ScoreTask::class);
    $this->achievements = app(AchievementService::class);
});

function tiersOf($user, string $key): array
{
    return UserAchievement::where('user_id', $user->id)->where('achievement_key', $key)->orderBy('tier')->pluck('tier')->all();
}

it('vergibt die erste Stufe beim ersten erledigten To-do mit Gold, Feed-Eintrag und Feier', function () {
    $goldBefore = $this->child->stats()->first()->gold;

    $result = ($this->score)(assignmentOf(makeTask($this->child), $this->child), 'up');

    expect(tiersOf($this->child, 'hard_worker'))->toBe([1])
        ->and($this->child->stats()->first()->gold)->toBe(round($goldBefore + $result->completion->gold + 2, 2))
        ->and(FeedEntry::where('type', 'achievement')->value('text'))->toContain('Fleißige Hände I')
        ->and(AchievementService::unseen($this->child))->toHaveCount(1);

    $this->achievements->markSeen($this->child);
    expect(AchievementService::unseen($this->child))->toBe([]);
});

it('gibt eine Stufe nach Rückgängig und erneutem Abhaken nicht doppelt', function () {
    $assignment = assignmentOf(makeTask($this->child), $this->child);

    ($this->score)($assignment, 'up');
    ($this->score)($assignment, 'down');
    ($this->score)($assignment, 'up');

    expect(UserAchievement::where('user_id', $this->child->id)->where('achievement_key', 'hard_worker')->count())->toBe(1)
        ->and(FeedEntry::where('type', 'achievement')->count())->toBe(1);
});

it('zählt Familienaufgaben mit Freigabe erst nach der Bestätigung', function () {
    $tasks = collect(range(1, 5))->map(fn ($i) => makeTask($this->parent, [
        'scope' => 'family',
        'title' => "Spülmaschine {$i}",
        'assignment_mode' => 'assigned',
        'assignees' => [$this->child->id],
        'approval_mode' => 'required',
        'star_reward' => 2,
    ]));

    $completions = $tasks->map(fn ($t) => ($this->score)(assignmentOf($t, $this->child), 'up')->completion);
    expect(tiersOf($this->child, 'family_helper'))->toBe([])
        ->and(tiersOf($this->child, 'hard_worker'))->toBe([]);

    $completions->each(fn ($c) => app(ReviewCompletion::class)->approve($c, $this->parent));

    expect(tiersOf($this->child, 'family_helper'))->toBe([1])
        ->and(tiersOf($this->child, 'hard_worker'))->toBe([1])
        ->and(tiersOf($this->child, 'star_collector'))->toBe([1]); // 10 Sterne
});

it('vergibt mehrere Stufen auf einmal, meldet im Feed aber nur die höchste', function () {
    $task = makeTask($this->child, ['type' => 'daily']);
    assignmentOf($task, $this->child)->update(['streak' => 6]);

    ($this->score)(assignmentOf($task, $this->child), 'up'); // Serie 7

    expect(tiersOf($this->child, 'streak'))->toBe([1, 2])
        ->and(FeedEntry::where('type', 'achievement')->where('data->achievement', 'streak')->count())->toBe(1)
        ->and(FeedEntry::where('type', 'achievement')->where('data->achievement', 'streak')->value('text'))->toContain('Feuer und Flamme II');
});

it('belohnt Level, Quest-Siege für alle Teilnehmenden und Reaktionen', function () {
    $this->child->stats()->update(['level' => 4, 'xp' => Formulas::xpToNextLevel(4) - 1]);
    $quest = app(QuestService::class)->start($this->parent, 'dust_monster', 'family');
    $quest->update(['progress' => $quest->goal - 1]);

    ($this->score)(assignmentOf(makeTask($this->child, ['difficulty' => 2]), $this->child), 'up');

    expect(tiersOf($this->child, 'monster_hunter'))->toBe([1])
        ->and(tiersOf($this->parent, 'monster_hunter'))->toBe([1])
        ->and(tiersOf($this->child, 'level'))->toBe([1]);

    $feed = app(FeedService::class);
    collect(range(1, 10))->map(fn ($i) => $feed->post($this->family->id, $this->child, 'test', '✅', "Eintrag {$i}"))
        ->each(fn ($e) => $feed->react($this->parent, $e, '👍'));
    expect(tiersOf($this->parent, 'cheerleader'))->toBe([1]);
});

it('zählt perfekte Tage beim Tageswechsel', function () {
    $task = makeTask($this->child, ['type' => 'daily']);
    app(RunDayCron::class)($this->child); // erster Aufruf setzt nur das Datum
    ($this->score)(assignmentOf($task, $this->child), 'up');

    $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', 'Europe/Berlin'));
    app(RunDayCron::class)($this->child->refresh());

    expect(tiersOf($this->child, 'perfect_day'))->toBe([1]);
});

it('berechnet Erfolge nachträglich ohne Feier', function () {
    $this->child->stats()->update(['level' => 12]);

    $this->artisan('heldenhaus:achievements-sync')->assertSuccessful();

    expect(tiersOf($this->child, 'level'))->toBe([1, 2])
        ->and(AchievementService::unseen($this->child))->toBe([])
        ->and(FeedEntry::where('type', 'achievement')->count())->toBe(0);
});

it('zeigt Erfolge im Web und in der API, auch die anderer Familienmitglieder', function () {
    ($this->score)(assignmentOf(makeTask($this->child), $this->child), 'up');

    $this->actingAs($this->parent)->get('/achievements?member='.$this->child->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Achievements')
            ->where('member.id', $this->child->id)
            ->where('achievements.0.key', 'hard_worker')
            ->where('achievements.0.tier', 1)
            ->where('achievements.0.next', 10));

    $this->actingAs($this->child)->get('/dashboard')
        ->assertInertia(fn ($page) => $page->where('newAchievements.0.title', 'Fleißige Hände I'));
    $this->actingAs($this->child)->post('/achievements/seen')->assertRedirect();
    expect(AchievementService::unseen($this->child))->toBe([]);

    Sanctum::actingAs($this->child, ['app']);
    $this->getJson('/api/v1/achievements')->assertOk()->assertJsonPath('achievements.0.unlocked.0.title', 'Fleißige Hände I');

    $result = $this->postJson('/api/v1/assignments/'.assignmentOf(makeTask($this->child), $this->child)->id.'/score/up')->assertOk();
    expect($result->json('achievements_new'))->toBe([]);

    $stranger = makeFamily()[2];
    $this->actingAs($this->child)->get('/achievements?member='.$stranger->id)->assertNotFound();
});
