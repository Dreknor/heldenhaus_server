<?php

use App\Domain\Gameplay\GameException;
use App\Domain\Gameplay\RunDayCron;
use App\Domain\Gameplay\ScoreTask;
use App\Domain\Support\OpenRouterClient;
use App\Domain\Support\StepService;
use App\Domain\Support\SupportService;
use App\Domain\Support\SurpriseService;
use App\Models\AiRequest;
use App\Models\DayLog;
use App\Models\FocusSession;
use App\Models\RecoveryQuest;
use App\Models\StepTemplate;
use App\Models\SupportSetting;
use App\Models\Task;
use App\Models\TaskCompletion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Random\Engine\Mt19937;
use Random\Randomizer;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-19 10:00', 'Europe/Berlin')); // Montag
    [$this->family, $this->parent, $this->child] = makeFamily();
    $this->cron = app(RunDayCron::class);
    ($this->cron)($this->child); // erster Lauf setzt nur das Datum
});

function nextDay($test, int $days = 1): void
{
    $test->travelTo(CarbonImmutable::parse('2026-10-19 10:00', 'Europe/Berlin')->addDays($days));
    ($test->cron)($test->child->refresh());
}

// ---------- Sanfter Modus ----------

it('sanfter Modus: kein HP-Schaden, der Boss heilt trotzdem', function () {
    SupportSetting::create(['user_id' => $this->child->id, 'gentle_mode' => true]);
    makeTask($this->child, ['type' => 'daily', 'title' => 'Lesen']);

    nextDay($this);

    $log = $this->child->stats()->first();
    expect($log->hp)->toBe(50.0)
        ->and(DayLog::first()->gentle)->toBeTrue()
        ->and(DayLog::first()->missed)->toHaveCount(1);
});

it('Eltern stellen den sanften Modus für Kinder ein, Kinder nur ihre Darstellung', function () {
    Sanctum::actingAs($this->child, ['app']);
    $this->putJson('/api/v1/me/support-settings', ['gentle_mode' => true])->assertUnprocessable();
    $this->putJson('/api/v1/me/support-settings', ['reduce_motion' => true, 'feedback_level' => 'subtle'])->assertOk()
        ->assertJsonPath('reduce_motion', true);

    Sanctum::actingAs($this->parent, ['app']);
    $this->putJson("/api/v1/members/{$this->child->id}/support-settings", ['gentle_mode' => true, 'low_energy_per_week' => 3])->assertOk()
        ->assertJsonPath('gentle_mode', true)->assertJsonPath('low_energy_left', 3);

    // Familienstandard greift, wenn keine eigene Einstellung gesetzt ist
    $this->putJson('/api/v1/family/support', ['gentle_mode_default' => true, 'ai_enabled' => false])->assertOk();
    expect(SupportService::gentle($this->parent->refresh()))->toBeTrue();

    [, $stranger] = makeFamily();
    Sanctum::actingAs($stranger, ['app']);
    $this->getJson("/api/v1/members/{$this->child->id}/support-settings")->assertUnprocessable();
});

// ---------- Serien pausieren und reparieren ----------

it('pausiert die Serie, ruht den Serienbonus und repariert sie mit der Erholungs-Quest', function () {
    $task = makeTask($this->child, ['type' => 'daily', 'title' => 'Zähne putzen']);
    assignmentOf($task, $this->child)->update(['streak' => 12, 'streak_best' => 12]);

    nextDay($this); // Montag verpasst
    $a = assignmentOf($task, $this->child);
    expect($a->streak)->toBe(12)->and($a->streak_paused_at)->not->toBeNull()
        ->and(RecoveryQuest::first())->needed->toBe(2)->streak_to_restore->toBe(12);

    // Erledigen während der Pause: kein Serienbonus beim Gold, Serie wächst nicht
    $first = app(ScoreTask::class)($a, 'up');
    expect($first->completion->streak_before)->toBe(12)->and(assignmentOf($task, $this->child)->streak)->toBe(12)
        ->and(RecoveryQuest::first()->done)->toBe(1);

    nextDay($this, 2);
    app(ScoreTask::class)(assignmentOf($task, $this->child), 'up');

    $a = assignmentOf($task, $this->child);
    expect(RecoveryQuest::first()->status)->toBe('restored')
        ->and($a->streak_paused_at)->toBeNull()
        ->and($a->streak)->toBe(12);
});

it('lässt eine abgelaufene Erholungs-Quest scheitern – der Bestwert bleibt', function () {
    $task = makeTask($this->child, ['type' => 'daily']);
    assignmentOf($task, $this->child)->update(['streak' => 5, 'streak_best' => 9]);

    nextDay($this);
    foreach (range(2, 6) as $day) {
        nextDay($this, $day);
    }

    $a = assignmentOf($task, $this->child);
    expect(RecoveryQuest::first()->status)->toBe('failed')
        ->and($a->streak)->toBe(0)
        ->and($a->streak_best)->toBe(9);
});

it('Streak-Schutz greift vor der Pause', function () {
    $task = makeTask($this->child, ['type' => 'daily']);
    assignmentOf($task, $this->child)->update(['streak' => 4]);
    $this->child->stats()->update(['streak_shields' => 1]);

    nextDay($this);

    expect(assignmentOf($task, $this->child)->streak_paused_at)->toBeNull()
        ->and(RecoveryQuest::count())->toBe(0);
});

// ---------- Energiespar-Tag ----------

it('Energiespar-Tag: nur Kern-Dailies zählen, Rest ist Bonus, Joker ist begrenzt', function () {
    $core = makeTask($this->child, ['type' => 'daily', 'title' => 'Medikament', 'is_core' => true, 'difficulty' => 1.5]);
    $other = makeTask($this->child, ['type' => 'daily', 'title' => 'Zimmer', 'difficulty' => 1]);
    $third = makeTask($this->child, ['type' => 'daily', 'title' => 'Üben', 'difficulty' => 1]);
    assignmentOf($other, $this->child)->update(['streak' => 3]);

    Sanctum::actingAs($this->child, ['app']);
    $this->postJson('/api/v1/me/low-energy')->assertCreated()->assertJsonPath('low_energy_today', true);
    $this->postJson('/api/v1/me/low-energy')->assertUnprocessable();

    app(ScoreTask::class)(assignmentOf($core, $this->child), 'up');
    $bonus = app(ScoreTask::class)(assignmentOf($third, $this->child), 'up');
    expect($bonus->completion->effects)->toContain('low_energy_bonus');

    nextDay($this);
    $log = DayLog::first();
    expect($log->low_energy)->toBeTrue()
        ->and($log->dailies_due)->toBe(1)->and($log->dailies_done)->toBe(1)
        ->and((float) $log->damage)->toBe(0.0)
        ->and(assignmentOf($other, $this->child)->streak_paused_at)->toBeNull()
        ->and($this->child->stats()->first()->hp)->toBe(50.0);

    // zweiter Joker in der Woche geht, der dritte nicht
    Sanctum::actingAs($this->child->refresh(), ['app']);
    $this->postJson('/api/v1/me/low-energy')->assertCreated();
    $this->travelTo(CarbonImmutable::parse('2026-10-21 10:00', 'Europe/Berlin'));
    $this->postJson('/api/v1/me/low-energy')->assertUnprocessable();
});

it('ohne markierte Kern-Dailies zählen die zwei leichtesten', function () {
    makeTask($this->child, ['type' => 'daily', 'title' => 'schwer', 'difficulty' => 2]);
    makeTask($this->child, ['type' => 'daily', 'title' => 'leicht', 'difficulty' => 0.1]);
    makeTask($this->child, ['type' => 'daily', 'title' => 'mittel', 'difficulty' => 1]);
    app(SupportService::class)->startLowEnergy($this->parent, $this->child);

    nextDay($this);

    expect(DayLog::first()->dailies_due)->toBe(2);
});

// ---------- Schritte ----------

it('belohnt jeden Schritt sofort, die Summe entspricht der normalen Belohnung', function () {
    $plain = makeTask($this->child, ['title' => 'Vergleich', 'difficulty' => 2]);
    $full = app(ScoreTask::class)(assignmentOf($plain, $this->child), 'up')->completion;
    app(ScoreTask::class)(assignmentOf($plain, $this->child), 'down');

    $task = makeTask($this->child, ['title' => 'Zimmer aufräumen', 'difficulty' => 2, 'checklist' => ['Wäsche', 'Stifte', 'Bett']]);
    $a = assignmentOf($task, $this->child);
    $steps = app(StepService::class);

    $one = $steps->score($a, 0);
    $two = $steps->score($a, 1, 'app', '8f0e1f3a-0000-4000-8000-000000000001');
    $replay = $steps->score($a, 1, 'app', '8f0e1f3a-0000-4000-8000-000000000001');
    expect(fn () => $steps->score($a, 0))->toThrow(GameException::class, 'schon erledigt');
    $last = $steps->score($a, 2);

    expect($one['final'])->toBeFalse()->and($replay['replayed'])->toBeTrue()
        ->and($last['final'])->toBeTrue()
        ->and(assignmentOf($task, $this->child)->completed)->toBeTrue()
        ->and($one['xp'] + $two['xp'] + $last['xp'])->toBe($full->xp)
        ->and(round($one['gold'] + $two['gold'] + $last['gold'], 2))->toBe(round($full->gold, 2))
        ->and(TaskCompletion::where('task_id', $task->id)->where('direction', 'step')->count())->toBe(2);
});

it('gibt Sterne für Familienaufgaben erst für die ganze Aufgabe', function () {
    $task = makeTask($this->parent, [
        'scope' => 'family', 'type' => 'todo', 'title' => 'Garage', 'assignment_mode' => 'assigned',
        'assignees' => [$this->child->id], 'approval_mode' => 'none', 'star_reward' => 3, 'checklist' => ['A', 'B'],
    ]);
    Sanctum::actingAs($this->child, ['app']);
    $id = assignmentOf($task, $this->child)->id;

    $this->postJson("/api/v1/assignments/{$id}/steps/0/score")->assertOk()->assertJsonPath('final', false);
    expect($this->child->stats()->first()->stars)->toBe(0);
    $this->postJson("/api/v1/assignments/{$id}/steps/1/score")->assertOk()->assertJsonPath('final', true);
    expect($this->child->stats()->first()->stars)->toBe(3);

    $board = $this->getJson('/api/v1/board')->json();
    expect($board)->toHaveKeys(['inbox', 'support', 'recoveries', 'focus']);
});

it('schlägt Schritte aus Vorlagen und Familie vor und speichert übernommene Schritte', function () {
    $task = makeTask($this->child, ['title' => 'Zimmer aufräumen!']);
    Sanctum::actingAs($this->child, ['app']);

    $this->postJson("/api/v1/tasks/{$task->id}/steps/suggest")->assertOk()
        ->assertJsonPath('suggestion.source', 'template')
        ->assertJsonPath('suggestion.steps.0', 'Wäsche aufheben')
        ->assertJsonPath('ai_available', false);
    // Kinder bekommen höchstens 5 Schritte
    expect(count($this->postJson("/api/v1/tasks/{$task->id}/steps/suggest")->json('suggestion.steps')))->toBeLessThanOrEqual(5);

    $this->putJson("/api/v1/tasks/{$task->id}/steps", ['steps' => ['Bett machen', 'Boden frei']])->assertOk();
    expect(StepTemplate::first()->steps)->toBe(['Bett machen', 'Boden frei']);

    $again = makeTask($this->child, ['title' => 'Zimmer aufräumen']);
    $this->postJson("/api/v1/tasks/{$again->id}/steps/suggest")->assertJsonPath('suggestion.source', 'family');
});

it('fragt OpenRouter nur mit Freigabe, ohne Namen und mit Budget', function () {
    config(['services.openrouter.key' => 'sk-test']);
    Http::fake(['openrouter.ai/*' => Http::response([
        'choices' => [['message' => ['content' => '{"steps": ["Formular öffnen", "Seite 1 ausfüllen", "Unterschreiben"]}']]],
        'usage' => ['prompt_tokens' => 80, 'completion_tokens' => 20, 'cost' => 0.0004],
    ])]);
    $task = makeTask($this->parent, ['title' => 'Sommerfest planen', 'notes' => 'bis Freitag']);
    Sanctum::actingAs($this->parent, ['app']);

    $this->postJson("/api/v1/tasks/{$task->id}/steps/suggest")->assertJsonPath('suggestion', null);
    Http::assertNothingSent();

    $this->family->update(['settings' => ['ai_enabled' => true]]);
    Sanctum::actingAs($this->parent->refresh(), ['app']);
    $this->postJson("/api/v1/tasks/{$task->id}/steps/suggest")->assertOk()
        ->assertJsonPath('suggestion.source', 'ai')->assertJsonPath('suggestion.steps.2', 'Unterschreiben');

    Http::assertSent(fn ($r) => ! str_contains($r->body(), $this->parent->name)
        && ! str_contains($r->body(), 'Familie Test')
        && str_starts_with($r['messages'][0]['content'], 'Du hilfst Erwachsenen')
        && $r['provider']['data_collection'] === 'deny');
    expect(AiRequest::first())->ok->toBeTrue()->prompt_tokens->toBe(80);

    // Budget aufgebraucht → keine KI mehr
    AiRequest::create(['family_id' => $this->family->id, 'purpose' => 'steps', 'cost_usd' => 5]);
    $this->postJson("/api/v1/tasks/{$task->id}/steps/suggest")->assertJsonPath('ai_available', false);

    // Kinder unter 13 nur mit zusätzlicher Freigabe
    $childTask = makeTask($this->child, ['title' => 'Referat vorbereiten']);
    AiRequest::truncate();
    Sanctum::actingAs($this->child, ['app']);
    $this->postJson("/api/v1/tasks/{$childTask->id}/steps/suggest")->assertJsonPath('ai_available', false);

    expect(OpenRouterClient::parse('kein json', 5))->toBeNull()
        ->and(OpenRouterClient::parse('{"steps": ["nur einer"]}', 5))->toBeNull();
});

// ---------- Kopf-Ablage ----------

it('Kopf-Ablage: ohne Kategorie anlegen, Einsortieren verlangt eine, unsortiert keine Funde', function () {
    Sanctum::actingAs($this->child, ['app']);

    $created = $this->postJson('/api/v1/inbox', ['title' => 'Oma anrufen'])->assertCreated();
    $task = Task::find($created->json('id'));
    expect($task->status)->toBe('inbox')->and($task->category)->toBeNull();

    $board = $this->getJson('/api/v1/board')->json();
    expect($board['inbox'][0]['title'])->toBe('Oma anrufen')->and($board['todos'])->toBe([]);

    $this->postJson("/api/v1/inbox/{$task->id}/sort", ['type' => 'todo'])->assertUnprocessable();
    $this->postJson("/api/v1/inbox/{$task->id}/sort", ['type' => 'daily', 'category' => 'others'])->assertOk()
        ->assertJsonPath('status', null)->assertJsonPath('type', 'daily');

    // Eltern sehen die Kopf-Ablage der Kinder nicht
    Sanctum::actingAs($this->parent, ['app']);
    $this->postJson("/api/v1/inbox/{$task->id}/sort", ['type' => 'todo', 'category' => 'others'])->assertNotFound();
});

// ---------- Überraschungen ----------

it('Überraschungen: Pech-Schutz erhöht die Chance, Tageslimit, nie Sterne', function () {
    config(['game.surprise.base_chance' => 0.0, 'game.surprise.pity_step' => 0.5, 'game.surprise.daily_limit' => 1]);
    $surprises = new SurpriseService(new Randomizer(new Mt19937(42)));
    $task = makeTask($this->child, ['type' => 'daily']);
    $stats = $this->child->stats()->first();

    expect(SurpriseService::chance(0))->toBe(0.0)->and(SurpriseService::chance(1))->toBe(0.35);

    $hits = 0;
    foreach (range(1, 10) as $i) {
        $completion = TaskCompletion::create(['task_id' => $task->id, 'user_id' => $this->child->id, 'direction' => 'up']);
        $hits += $surprises->roll($completion, $task, $stats) ? 1 : 0;
    }

    expect($hits)->toBe(1)
        ->and($stats->surprises_today)->toBe(1)
        ->and($this->child->stats()->first()->stars)->toBe(0);
});

// ---------- Fokus-Zeiten ----------

it('Fokus-Zeit: starten, verlängern, gemeinsam schaffen gibt beiden einen Bonus', function () {
    $task = makeTask($this->child, ['title' => 'Hausaufgaben']);
    Sanctum::actingAs($this->child, ['app']);

    $session = $this->postJson('/api/v1/focus-sessions', ['minutes' => 15, 'task_id' => $task->id])->assertCreated()->json();
    expect($session['remaining_seconds'])->toBe(900);

    $this->postJson("/api/v1/focus-sessions/{$session['id']}/extend", ['minutes' => 5])->assertOk()->assertJsonPath('planned_minutes', 20);
    $this->postJson("/api/v1/focus-sessions/{$session['id']}/invite", ['member_id' => $this->parent->id])->assertOk()->assertJsonPath('shared', true);

    $goldBefore = goldWithoutAchievements($this->child);
    $this->postJson("/api/v1/focus-sessions/{$session['id']}/finish")->assertOk()->assertJsonPath('bonus_gold', 0);

    Sanctum::actingAs($this->parent, ['app']);
    $partner = FocusSession::where('user_id', $this->parent->id)->first();
    $this->postJson("/api/v1/focus-sessions/{$partner->id}/finish")->assertOk()->assertJsonPath('bonus_gold', 3);

    expect(goldWithoutAchievements($this->child))->toBe($goldBefore + 3)
        ->and(FocusSession::where('user_id', $this->child->id)->first()->bonus_gold)->toBe(3.0);

    // Abbrechen hat keine Folgen, fremde Fokus-Zeiten sind tabu
    $own = $this->postJson('/api/v1/focus-sessions', ['minutes' => 5])->json('id');
    $this->postJson("/api/v1/focus-sessions/{$own}/abort")->assertOk()->assertJsonPath('result', 'aborted');
    $this->postJson("/api/v1/focus-sessions/{$session['id']}/abort")->assertUnprocessable();
});
