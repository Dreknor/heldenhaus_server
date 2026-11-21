<?php

use App\Domain\Gameplay\RunDayCron;
use App\Domain\Gameplay\ScoreTask;
use App\Domain\Insights\ActivityScores;
use App\Domain\Insights\NightlyInsights;
use App\Domain\Insights\ReviewService;
use App\Enums\FamilyRole;
use App\Models\ActivityScore;
use App\Models\FeedEntry;
use App\Models\Review;
use App\Models\Task;
use App\Models\TaskCompletion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;

/** Abschluss mit festem Zeitpunkt (Ortszeit Berlin) anlegen. */
function doneAt(User $user, Task $task, string $at, int $times = 1): void
{
    for ($i = 0; $i < $times; $i++) {
        $c = new TaskCompletion(['task_id' => $task->id, 'user_id' => $user->id, 'direction' => 'up', 'delta' => 1, 'xp' => 10, 'status' => 'none']);
        $c->created_at = CarbonImmutable::parse($at, 'Europe/Berlin')->addMinutes($i);
        $c->save();
    }
}

beforeEach(function () {
    // Montag, 9. November 2026, 10 Uhr – die Vorwoche (2.–8.11.) ist abgeschlossen
    $this->travelTo(CarbonImmutable::parse('2026-11-09 10:00', 'Europe/Berlin'));
    [$this->family, $this->parent, $this->child] = makeFamily();
    $this->child->forceFill(['created_at' => now()->subDays(30)])->save();
    $this->task = makeTask($this->child, ['title' => 'Zähne putzen']);
});

it('füllt Tagespunkte nach der Boss-Schaden-Formel und zieht sie beim Tageswechsel nach', function () {
    app(RunDayCron::class)($this->child);
    app(ScoreTask::class)(assignmentOf($this->task, $this->child), 'up');
    makeTask($this->child, ['type' => 'daily']);

    $this->travel(1)->days();
    app(RunDayCron::class)($this->child->refresh());

    $score = ActivityScore::where('user_id', $this->child->id)->whereDate('date', '2026-11-09')->first();
    expect($score->points)->toBe(4.0)->and($score->tasks)->toBe(1)->and($score->units)->toBe(1);
});

it('nimmt den Median der letzten 14 Tage ohne Gasthaus-Tage', function () {
    foreach ([1, 2, 3] as $d) {
        doneAt($this->child, $this->task, "2026-11-0{$d} 17:00", 3);  // 12 Punkte
    }
    doneAt($this->child, $this->task, '2026-11-05 17:00', 20);        // Ausreißer
    app(ActivityScores::class)->fill($this->child);
    ActivityScore::where('user_id', $this->child->id)->where('tasks', 0)->update(['sleeping' => true]); // Rest: Gasthaus

    expect(app(ActivityScores::class)->value($this->child))->toMatchArray(['points' => 12.0, 'own' => true]);
});

it('vergleicht im Wochenrückblick nur mit sich selbst und nie mit einem Minus', function () {
    doneAt($this->child, $this->task, '2026-10-27 17:00', 9);   // Vorvorwoche: 9
    doneAt($this->child, $this->task, '2026-11-03 17:00', 2);   // Vorwoche: 3 an 2 Tagen
    doneAt($this->child, $this->task, '2026-11-06 17:00', 1);

    $review = app(ReviewService::class)->get($this->child, 'week');
    $text = json_encode($review->data, JSON_UNESCAPED_UNICODE);

    expect($review->period_start->toDateString())->toBe('2026-11-02')
        ->and($review->data['message'])->toBe('Du hast an 2 Tagen Aufgaben geschafft.')
        ->and($review->data['best_day']['weekday'])->toBe('Dienstag')
        ->and($text)->not->toMatch('/(?<!\d)[-−]\s?\d/u')->not->toContain('weniger');

    // Mehr als in der Woche davor → „mehr“
    Review::query()->delete();
    doneAt($this->child, $this->task, '2026-11-07 17:00', 9);
    expect(app(ReviewService::class)->get($this->child, 'week')->data['message'])->toBe('3 Aufgaben mehr als letzte Woche!');
});

it('zeigt Eltern private Teen-Inhalte nur als Anzahl', function () {
    $teen = joinFamily($this->family, FamilyRole::Child, ['name' => 'Tim', 'birth_year' => now()->year - 14, 'timezone' => 'Europe/Berlin', 'day_start' => 0]);
    $teen->forceFill(['created_at' => now()->subDays(30)])->save();
    $diary = makeTask($teen, ['title' => 'Geheimes Tagebuch', 'is_private' => true]);
    doneAt($teen, $diary, '2026-11-04 20:00', 4);
    assignmentOf($diary, $teen)->update(['streak' => 5]);

    Sanctum::actingAs($teen, ['app']);
    $this->getJson('/api/v1/me/review/week')->assertOk()->assertSee('Geheimes Tagebuch');

    Sanctum::actingAs($this->parent, ['app']);
    $this->getJson("/api/v1/members/{$teen->id}/review/week")->assertOk()
        ->assertDontSee('Geheimes Tagebuch')
        ->assertJsonPath('review.tasks', 4)
        ->assertJsonPath('review.private_items', 3); // Höhepunkt Serie, Höhepunkt Lieblingsaufgabe, längste Serie
    $this->getJson("/api/v1/members/{$teen->id}/stats")->assertOk()->assertDontSee('Geheimes Tagebuch')->assertJsonPath('private_tasks', 4);

    // Geschwister sehen fremde Rückblicke nicht
    Sanctum::actingAs($this->child, ['app']);
    $this->getJson("/api/v1/members/{$teen->id}/review/week")->assertForbidden();
});

it('erzeugt die Monatskarte mit Heatmap und teilt sie einmal in den Feed', function () {
    doneAt($this->child, $this->task, '2026-10-05 17:00', 4);
    doneAt($this->child, $this->task, '2026-10-06 17:00', 1);

    Sanctum::actingAs($this->child, ['app']);
    $review = $this->getJson('/api/v1/me/review/month')->assertOk()
        ->assertJsonPath('review.label', 'Oktober 2026')
        ->assertJsonPath('review.tasks', 5)
        ->assertJsonCount(31, 'review.heatmap')
        ->assertJsonPath('review.heatmap.4.level', 4)
        ->assertJsonPath('review.heatmap.5.level', 1)
        ->json('review');
    expect($review['pride'])->toStartWith('Darauf kannst du stolz sein');

    $this->postJson("/api/v1/me/review/{$review['id']}/share")->assertOk();
    $this->postJson("/api/v1/me/review/{$review['id']}/share")->assertUnprocessable();
    expect(FeedEntry::where('type', 'review_shared')->value('text'))->toContain('Monatskarte Oktober 2026')->not->toContain('Zähne putzen');

    Sanctum::actingAs($this->parent, ['app']);
    $this->postJson("/api/v1/me/review/{$review['id']}/seen")->assertForbidden();
});

it('erzeugt Rückblicke erst montags ab 5 Uhr in der Zeitzone der Familie', function () {
    $nightly = app(NightlyInsights::class);

    expect($nightly->family($this->family, CarbonImmutable::parse('2026-11-09 04:30', 'Europe/Berlin'))['reviews'])->toBe(0)
        ->and(Review::count())->toBe(0);

    $this->travelTo(CarbonImmutable::parse('2026-11-09 05:10', 'Europe/Berlin'));
    $done = $nightly->family($this->family, CarbonImmutable::now());
    expect($done['reviews'])->toBeGreaterThan(0)
        ->and(Review::where('user_id', $this->child->id)->where('kind', 'week')->value('period_start')->toDateString())->toBe('2026-11-02')
        ->and($nightly->family($this->family, CarbonImmutable::now())['reviews'])->toBe(0); // nur einmal
});

it('zeigt den ungelesenen Wochenrückblick als Karte im Heldenpanel', function () {
    doneAt($this->child, $this->task, '2026-11-04 17:00', 2);

    $this->actingAs($this->child)->get('/dashboard')
        ->assertInertia(fn ($page) => $page->where('insights.review.kind', 'week')->where('insights.review.tasks', 2));
    $id = Review::where('user_id', $this->child->id)->where('kind', 'week')->value('id');

    $this->post("/reviews/{$id}/seen")->assertRedirect();
    $this->get('/dashboard')->assertInertia(fn ($page) => $page->where('insights.review', null));
    $this->get('/stats')->assertOk()->assertInertia(fn ($page) => $page->component('stats/Index')->where('reviews.week.tasks', 2)->where('family', null));
});
