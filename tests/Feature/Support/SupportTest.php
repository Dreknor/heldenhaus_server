<?php

use App\Domain\Gameplay\GameException;
use App\Domain\Gameplay\RunDayCron;
use App\Domain\Gameplay\ScoreTask;
use App\Domain\Support\OpenRouterClient;
use App\Domain\Support\StepService;
use App\Domain\Support\SupportService;
use App\Domain\Support\SurpriseService;
use App\Http\Presenters\GamePresenter;
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
use Inertia\Testing\AssertableInertia as Assert;
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
        'choices' => [['message' => ['content' => '{"steps": ["Formular öffnen", "Seite 1 ausfüllen", "Unterschreiben"]}'], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 80, 'completion_tokens' => 20, 'cost' => 0.0004],
    ])]);
    $task = makeTask($this->parent, ['title' => 'Sommerfest planen', 'notes' => 'bis Freitag']);
    Sanctum::actingAs($this->parent, ['app']);

    $this->postJson("/api/v1/tasks/{$task->id}/steps/suggest")->assertJsonPath('suggestion', null);
    Http::assertNothingSent();

    $this->family->update(['settings' => ['ai_enabled' => true]]);
    SupportSetting::create(['user_id' => $this->parent->id, 'ai_steps' => true]);
    Sanctum::actingAs($this->parent->refresh(), ['app']);
    $this->postJson("/api/v1/tasks/{$task->id}/steps/suggest")->assertOk()
        ->assertJsonPath('suggestion.source', 'ai')->assertJsonPath('suggestion.steps.2', 'Unterschreiben');

    Http::assertSent(fn ($r) => ! str_contains($r->body(), $this->parent->name)
        && ! str_contains($r->body(), 'Familie Test')
        && str_starts_with($r['messages'][0]['content'], 'Du hilfst Erwachsenen')
        && $r['max_tokens'] === 1600
        && $r['reasoning'] === ['effort' => 'none', 'exclude' => true]
        && $r['provider']['data_collection'] === 'deny');
    expect(AiRequest::first())->ok->toBeTrue()
        ->prompt_tokens->toBe(80)
        ->response_status->toBe(200)
        ->finish_reason->toBe('stop')
        ->response_payload->toContain('Formular');

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

it('übernimmt bei aktivierter persönlicher KI-Freigabe beim Anlegen automatisch Schrittvorschläge', function () {
    config(['services.openrouter.key' => 'sk-test']);
    $this->family->update(['settings' => ['ai_enabled' => true]]);
    SupportSetting::create(['user_id' => $this->parent->id, 'ai_steps' => true]);
    Http::fake(['openrouter.ai/*' => Http::response([
        'choices' => [['message' => ['content' => '{"steps": ["Unterlagen zusammensuchen", "Formular ausfüllen"]}']]],
        'usage' => ['prompt_tokens' => 40, 'completion_tokens' => 15, 'cost' => 0.0002],
    ])]);
    Sanctum::actingAs($this->parent, ['app']);

    $this->postJson('/api/v1/tasks', [
        'type' => 'todo',
        'scope' => 'family',
        'title' => 'Pflanzen gießen',
        'category' => 'others',
        'difficulty' => 1,
        'approval_mode' => 'none',
        'assignment_mode' => 'assigned',
        'assignees' => [$this->parent->id],
    ])->assertCreated()->assertJsonPath('checklist', []);
    Http::assertNothingSent();

    $this->postJson('/api/v1/tasks', [
        'type' => 'todo',
        'scope' => 'family',
        'title' => 'Versicherungsformular ausfüllen',
        'category' => 'others',
        'difficulty' => 1.5,
        'approval_mode' => 'none',
        'assignment_mode' => 'assigned',
        'assignees' => [$this->parent->id],
    ])->assertCreated()
        ->assertJsonPath('checklist', ['Unterlagen zusammensuchen', 'Formular ausfüllen']);

    $createdTask = Task::where('title', 'Versicherungsformular ausfüllen')->firstOrFail();
    $assignment = $createdTask->assignments()->with('task', 'completions')->firstOrFail();
    $presentedAssignment = GamePresenter::assignment($assignment);
    expect($presentedAssignment['checklist'])->toBe(['Unterlagen zusammensuchen', 'Formular ausfüllen'])
        ->and($presentedAssignment['steps'][0])->toBe([
            'index' => 0,
            'title' => 'Unterlagen zusammensuchen',
            'done' => false,
        ]);

    Http::assertSent(fn ($request) => $request['model'] === config('services.openrouter.model'));
    expect(AiRequest::first())->ok->toBeTrue()->prompt_tokens->toBe(40);
});

it('speichert abgeschnittene Provider-Antworten als Fehler und zeigt sie nur Admins', function () {
    config(['services.openrouter.key' => 'sk-test']);
    Http::fake(['openrouter.ai/*' => Http::response([
        'choices' => [['message' => ['content' => '{"steps": ['], 'finish_reason' => 'length']],
        'usage' => ['prompt_tokens' => 90, 'completion_tokens' => 300, 'cost' => 0.0008],
    ])]);
    $this->family->update(['settings' => ['ai_enabled' => true]]);
    SupportSetting::create(['user_id' => $this->parent->id, 'ai_steps' => true]);

    expect(app(OpenRouterClient::class)->steps($this->parent, 'Formular sortieren', null, 7))->toBeNull();
    $request = AiRequest::first();
    expect($request->ok)->toBeFalse()
        ->and($request->completion_tokens)->toBe(600)
        ->and($request->finish_reason)->toBe('length')
        ->and($request->error_message)->toContain('abgeschnitten');

    $this->actingAs($this->parent)->get('/admin/ai-requests')->assertForbidden();

    $this->parent->forceFill(['is_admin' => true])->save();
    $this->actingAs($this->parent)->get('/admin/ai-requests')->assertInertia(fn (Assert $page) => $page
        ->component('admin/AiRequests')
        ->where('requests.0.ok', false)
        ->where('requests.0.finish_reason', 'length')
        ->where('requests.0.response_payload', $request->response_payload));
});

it('wiederholt bei abgeschnittener Antwort einmal mit höherem Token-Limit', function () {
    config(['services.openrouter.key' => 'sk-test']);
    Http::fake([
        'openrouter.ai/*' => Http::sequence()
            ->push([
                'choices' => [['message' => ['content' => '{"steps": ['], 'finish_reason' => 'length']],
                'usage' => ['prompt_tokens' => 90, 'completion_tokens' => 1600, 'cost' => 0.001],
            ])
            ->push([
                'choices' => [['message' => ['content' => '{"steps": ["Unterlagen sammeln", "Formular ausfüllen"]}'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 90, 'completion_tokens' => 30, 'cost' => 0.0002],
            ]),
    ]);

    expect(app(OpenRouterClient::class)->steps($this->parent, 'Formular ausfüllen', null, 7))
        ->toBe(['Unterlagen sammeln', 'Formular ausfüllen']);

    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => $request['max_tokens'] === 1600);
    Http::assertSent(fn ($request) => $request['max_tokens'] === 3000);
    $request = AiRequest::first();
    expect($request->ok)->toBeTrue()
        ->and($request->prompt_tokens)->toBe(180)
        ->and($request->completion_tokens)->toBe(1630)
        ->and($request->finish_reason)->toBe('stop')
        ->and($request->response_payload)->toContain('finish_reason');
});

it('Eltern verwalten die Familien-KI-Freigabe und Support-Einstellungen ihrer Kinder', function () {
    $this->actingAs($this->parent)
        ->put('/family/support', ['gentle_mode_default' => true, 'ai_enabled' => true, 'ai_budget_usd' => 2.5])
        ->assertSessionHasNoErrors();

    $this->put("/members/{$this->child->id}/support-settings", [
        'gentle_mode' => true,
        'ai_steps' => true,
        'max_steps' => 6,
        'low_energy_per_week' => 3,
    ])->assertSessionHasNoErrors();

    expect($this->family->fresh()->setting('ai_enabled'))->toBeTrue()
        ->and($this->family->fresh()->setting('ai_budget_usd'))->toBe(2.5)
        ->and(SupportSetting::find($this->child->id))->ai_steps->toBeTrue()
        ->and(SupportSetting::find($this->child->id))->max_steps->toBe(6);

    $this->get('/family/settings')->assertInertia(fn (Assert $page) => $page
        ->component('family/Settings')
        ->where('family.settings.ai_enabled', true)
        ->where('openrouterConfigured', false)
        ->has('members.0.supportSettings.ai_steps'));
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

it('zeigt Alltagshilfen im Dashboard und unterstützt die Web-Aktionen', function () {
    $task = makeTask($this->child, ['title' => 'Zimmer aufräumen', 'checklist' => null]);
    $this->actingAs($this->child);

    $this->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->has('inbox')
        ->has('support.low_energy_left')
        ->where('focus', null)
        ->where('focusMembers.0.id', $this->parent->id));

    $this->post('/inbox', ['title' => 'Oma anrufen'])->assertRedirect();
    $captured = Task::where('owner_id', $this->child->id)->where('title', 'Oma anrufen')->firstOrFail();
    expect($captured->status)->toBe('inbox')->and($captured->category)->toBeNull();

    $this->post("/inbox/{$captured->id}/sort", ['type' => 'todo', 'category' => 'others'])->assertRedirect();
    expect($captured->fresh()->status)->toBeNull()->and($captured->fresh()->category)->toBe('others');

    $this->post('/inbox', ['title' => 'Später löschen'])->assertRedirect();
    $toDelete = Task::where('owner_id', $this->child->id)->where('title', 'Später löschen')->firstOrFail();
    $this->post("/inbox/{$toDelete->id}/sort", ['action' => 'delete'])->assertRedirect();
    expect($toDelete->fresh()->archived_at)->not->toBeNull();

    $this->post('/me/low-energy')->assertRedirect();
    expect(SupportService::isLowEnergy($this->child, $this->child->gameDate()))->toBeTrue();
    $this->delete('/me/low-energy')->assertRedirect();

    $this->post('/focus-sessions', ['minutes' => 15, 'task_id' => $task->id])->assertRedirect();
    $session = FocusSession::where('user_id', $this->child->id)->whereNull('ended_at')->firstOrFail();
    $this->post("/focus-sessions/{$session->id}/invite", ['member_id' => $this->parent->id])->assertRedirect();
    expect(FocusSession::where('user_id', $this->parent->id)->whereNull('ended_at')->exists())->toBeTrue();
    $this->post("/focus-sessions/{$session->id}/extend", ['minutes' => 5])->assertRedirect();
    $this->post("/focus-sessions/{$session->id}/abort")->assertRedirect();

    $this->postJson("/tasks/{$task->id}/steps/suggest")->assertOk()->assertJsonPath('suggestion.source', 'template');
    $this->putJson("/tasks/{$task->id}/steps", ['steps' => ['Kleidung sortieren', 'Boden freiräumen']])
        ->assertOk()->assertJsonPath('checklist.1', 'Boden freiräumen');
});
