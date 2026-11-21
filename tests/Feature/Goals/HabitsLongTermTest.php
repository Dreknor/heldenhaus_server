<?php

use App\Domain\Badges\BadgeService;
use App\Domain\Gameplay\GameException;
use App\Domain\Gameplay\RunDayCron;
use App\Domain\Gameplay\ScoreTask;
use App\Domain\Goals\MasteryService;
use App\Domain\Goals\ReminderService;
use App\Domain\Goals\StarFadeService;
use App\Domain\Housing\HomeService;
use App\Enums\TaskType;
use App\Jobs\SendPushNotification;
use App\Models\BuildContribution;
use App\Models\BuildProject;
use App\Models\DayLog;
use App\Models\DeviceToken;
use App\Models\FocusSession;
use App\Models\Goal;
use App\Models\HaWebhook;
use App\Models\Home;
use App\Models\NotificationSetting;
use App\Models\ReminderLog;
use App\Models\TaskCompletion;
use App\Models\UserBadge;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    [$this->family, $this->parent, $this->child] = makeFamily();
    $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00', 'Europe/Berlin'));
});

function doneOn($assignment, string $day, string $status = 'none'): void
{
    TaskCompletion::forceCreate([
        'task_id' => $assignment->task_id, 'assignment_id' => $assignment->id, 'user_id' => $assignment->user_id, 'direction' => 'up',
        'value_before' => 0, 'delta' => 1, 'xp' => 1, 'gold' => 1, 'hp' => 0, 'stars' => 0, 'status' => $status, 'source' => 'web',
        'created_at' => CarbonImmutable::parse("{$day} 12:00", 'Europe/Berlin')->utc(), 'updated_at' => now(),
    ]);
}

// ---------- §14.2 Erinnerungen ----------

it('erinnert einmal pro Aufgabe und Tag, höchstens fünfmal pro Person, nie in Ruhezeit oder Fokus-Zeit', function () {
    Queue::fake();
    DeviceToken::create(['user_id' => $this->child->id, 'token' => 'kind']);
    $reminders = app(ReminderService::class);

    $tasks = collect(range(1, 7))->map(fn ($i) => makeTask($this->child, ['type' => 'daily', 'title' => "Aufgabe {$i}", 'repeat' => ['type' => 'daily'], 'remind_at' => '17:00']));

    $this->travelTo(CarbonImmutable::parse('2026-11-02 16:59', 'Europe/Berlin'));
    expect($reminders->dispatchDue())->toBe(0);

    $this->travelTo(CarbonImmutable::parse('2026-11-02 17:01', 'Europe/Berlin'));
    expect($reminders->dispatchDue())->toBe(5)
        ->and($reminders->dispatchDue())->toBe(0)
        ->and(ReminderLog::where('user_id', $this->child->id)->count())->toBe(5);
    // Reine Datennachricht ohne Aufgabentitel: die App entscheidet, ob sie sie zeigt (lokal schon geplant?)
    Queue::assertPushed(SendPushNotification::class, fn ($job) => $job->type === null && $job->data['type'] === 'reminder'
        && ($job->data['show'] ?? null) === '1' && $job->data['title'] === 'Erinnerung' && ! str_contains(json_encode($job->data), 'Aufgabe'));

    // Nächster Tag: Ruhezeit hält zurück, danach Fokus-Zeit
    NotificationSetting::create(['user_id' => $this->child->id, 'push_enabled' => true, 'quiet_start' => '16:00', 'quiet_end' => '18:00']);
    $this->travelTo(CarbonImmutable::parse('2026-11-03 17:01', 'Europe/Berlin'));
    expect($reminders->dispatchDue())->toBe(0);

    NotificationSetting::where('user_id', $this->child->id)->delete();
    FocusSession::create(['user_id' => $this->child->id, 'planned_minutes' => 15, 'started_at' => now(), 'ends_at' => now()->addMinutes(15)]);
    expect($reminders->dispatchDue())->toBe(0);
    FocusSession::query()->update(['ended_at' => now()]);

    // Erledigt = keine Erinnerung
    app(ScoreTask::class)(assignmentOf($tasks[0], $this->child), 'up');
    expect($reminders->dispatchDue())->toBe(5)
        ->and(ReminderLog::whereDate('date', '2026-11-03')->where('assignment_id', assignmentOf($tasks[0], $this->child)->id)->exists())->toBeFalse();
});

it('sendet das HA-Ereignis nur auf Wunsch und nie bei privaten Aufgaben', function () {
    HaWebhook::create(['family_id' => $this->family->id, 'url' => 'https://ha.example/api/webhook/hh', 'secret' => 'geheim']);
    Http::fake(['ha.example/*' => Http::response('', 200)]);
    makeTask($this->child, ['type' => 'daily', 'title' => 'Bad', 'repeat' => ['type' => 'daily'], 'remind_at' => '07:00', 'remind_ha' => true, 'ha_event_key' => 'teeth']);
    makeTask($this->child, ['type' => 'daily', 'title' => 'Geheim', 'repeat' => ['type' => 'daily'], 'remind_at' => '07:00', 'remind_ha' => true, 'is_private' => true]);

    $this->travelTo(CarbonImmutable::parse('2026-11-02 07:05', 'Europe/Berlin'));
    app(ReminderService::class)->dispatchDue();

    $events = Http::recorded()->map(fn ($pair) => [$pair[0]->header('X-Heldenhaus-Event')[0] ?? null, $pair[0]->data()['data'] ?? []]);
    expect($events)->toHaveCount(1)
        ->and($events[0][0])->toBe('reminder')
        ->and($events[0][1])->toMatchArray(['member' => 'Lea', 'task_key' => 'teeth']);
});

it('„Später“ erinnert genau einmal erneut, nach drei ignorierten Erinnerungen kommt die Frage', function () {
    Queue::fake();
    $task = makeTask($this->child, ['type' => 'daily', 'title' => 'Lesen', 'repeat' => ['type' => 'daily'], 'remind_at' => '18:00']);
    $a = assignmentOf($task, $this->child);
    $reminders = app(ReminderService::class);

    $this->travelTo(CarbonImmutable::parse('2026-11-02 18:00', 'Europe/Berlin'));
    expect($reminders->dispatchDue())->toBe(1);
    $reminders->snooze($this->child, $a->load('task', 'user'), 15);
    expect(fn () => $reminders->snooze($this->child, $a, 30))->toThrow(GameException::class);

    $this->travelTo(CarbonImmutable::parse('2026-11-02 18:10', 'Europe/Berlin'));
    expect($reminders->dispatchDue())->toBe(0);
    $this->travelTo(CarbonImmutable::parse('2026-11-02 18:16', 'Europe/Berlin'));
    expect($reminders->dispatchDue())->toBe(1)->and($reminders->dispatchDue())->toBe(0);

    foreach (['2026-11-03', '2026-11-04'] as $day) {
        $this->travelTo(CarbonImmutable::parse("{$day} 18:01", 'Europe/Berlin'));
        $a->forceFill(['completed' => false])->save();
        $reminders->dispatchDue();
    }
    $this->travelTo(CarbonImmutable::parse('2026-11-05 08:00', 'Europe/Berlin'));
    $reminders->evaluatePast($this->child);

    expect($a->refresh()->ignored_reminders)->toBe(3)->and($a->reminder_question)->toBeTrue();
    $this->actingAs($this->child)->get('/dashboard')->assertInertia(fn ($page) => $page->where('dailies.0.reminder_question', true));

    $this->post("/tasks/{$task->id}/reminder/feedback", ['action' => 'pause'])->assertSessionHasNoErrors();
    expect($a->refresh()->reminder_question)->toBeFalse()
        ->and($task->refresh()->paused_until->toDateString())->toBe('2026-11-19')
        ->and($task->isDueOn(CarbonImmutable::parse('2026-11-10')))->toBeFalse()
        ->and($task->isDueOn(CarbonImmutable::parse('2026-11-20')))->toBeTrue();
});

// ---------- §14.3 Meistern ----------

it('bietet Meistern erst nach 60 Tagen mit 80 % an, Energiespar-Tage zählen nicht', function () {
    $this->travelTo(CarbonImmutable::parse('2026-08-01 10:00', 'Europe/Berlin'));
    $task = makeTask($this->child, ['type' => 'daily', 'title' => 'Zähneputzen', 'repeat' => ['type' => 'daily']]);
    $a = assignmentOf($task, $this->child);
    $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00', 'Europe/Berlin'));
    $mastery = app(MasteryService::class);

    // 60 Tage zurück: 47 erledigt, 8 Energiespar-Tage, 5 verpasst → 47/52 = 90 %
    $day = CarbonImmutable::parse('2026-09-03');
    for ($i = 0; $i < 60; $i++, $day = $day->addDay()) {
        match (true) {
            $i < 8 => DayLog::create(['user_id' => $this->child->id, 'date' => $day->toDateString(), 'dailies_due' => 1, 'dailies_done' => 0, 'low_energy' => true]),
            $i < 13 => null,
            default => doneOn($a, $day->toDateString()),
        };
    }

    $a->forceFill(['value' => 8])->save();
    expect($mastery->eligible($a->refresh()->load('task', 'user')))->toBeFalse(); // Farbstufe fehlt noch

    $a->forceFill(['value' => 12])->save();
    expect($mastery->eligible($a->refresh()->load('task', 'user')))->toBeTrue()
        ->and($mastery->scan($this->child))->toBe(1)
        ->and($mastery->cards($this->child)['offers'][0]['title'])->toBe('Zähneputzen');

    // Ohne die Energiespar-Tage wären es 47/60 = 78 % – zu wenig
    DayLog::query()->delete();
    $task->update(['mastery_offered_at' => null]);
    expect($mastery->eligible($a->refresh()->load('task', 'user')))->toBeFalse();
});

it('Meistern nimmt die Aufgabe von der Liste, „Klappt es noch? – Nein“ holt sie zurück', function () {
    $task = makeTask($this->child, ['type' => 'daily', 'title' => 'Bett machen', 'repeat' => ['type' => 'daily']]);
    $task->update(['mastery_offered_at' => now()]);
    $mastery = app(MasteryService::class);

    $mastery->master($this->child, $task);
    expect(UserBadge::where('key', "mastery:{$task->id}")->count())->toBe(1)
        ->and($task->refresh()->isDueOn(CarbonImmutable::parse('2026-11-03')))->toBeFalse();
    $this->actingAs($this->child)->get('/dashboard')->assertInertia(fn ($page) => $page->where('dailies', []));

    // Gemeisterte Aufgabe zählt im Tageswechsel nicht als verpasst
    $this->travelTo(CarbonImmutable::parse('2026-11-03 10:00', 'Europe/Berlin'));
    $hp = $this->child->stats()->first()->hp;
    app(RunDayCron::class)($this->child->fresh());
    expect($this->child->stats()->first()->hp)->toBe($hp);

    $this->travelTo(CarbonImmutable::parse('2026-11-09 10:00', 'Europe/Berlin'));
    expect($mastery->cards($this->child)['checks'])->toHaveCount(1);
    $mastery->stillOk($this->child, $task, false);

    expect($task->refresh()->mastered_at)->toBeNull()
        ->and($mastery->cards($this->child)['checks'])->toBe([]);
});

it('„Seltener“ macht aus der Daily eine Gewohnheit mit Wochenziel 5 von 7', function () {
    $task = makeTask($this->child, ['type' => 'daily', 'title' => 'Üben', 'repeat' => ['type' => 'daily']]);
    $task->update(['mastery_offered_at' => now()]);

    app(MasteryService::class)->lessOften($this->child, $task);

    $goal = Goal::where('user_id', $this->child->id)->first();
    expect($task->refresh()->type)->toBe(TaskType::Habit)
        ->and($goal)->toMatchArray(['type' => 'weekly', 'target' => 5, 'source' => 'mastery'])
        ->and($goal->tasks()->pluck('tasks.id')->all())->toBe([$task->id]);
});

it('Familienaufgaben werden nicht gemeistert', function () {
    $task = makeTask($this->parent, ['type' => 'daily', 'scope' => 'family', 'title' => 'Tisch decken', 'assignees' => [$this->child->id], 'assignment_mode' => 'assigned', 'approval_mode' => 'none']);

    expect(app(MasteryService::class)->eligible(assignmentOf($task, $this->child)->forceFill(['value' => 20])->load('task', 'user')))->toBeFalse()
        ->and(fn () => app(MasteryService::class)->master($this->child, $task))->toThrow(GameException::class);
});

// ---------- §14.3 Ehrensache ----------

it('schlägt Ausschleichen erst nach 30 Bestätigungen vor und ändert nie ohne Eltern', function () {
    $task = makeTask($this->parent, ['type' => 'todo', 'scope' => 'family', 'title' => 'Spülmaschine', 'assignees' => [$this->child->id],
        'assignment_mode' => 'assigned', 'approval_mode' => 'none', 'star_reward' => 4]);
    $a = assignmentOf($task, $this->child);
    $fade = app(StarFadeService::class);

    foreach (range(1, 29) as $i) {
        doneOn($a, '2026-10-'.str_pad((string) ($i % 28 + 1), 2, '0', STR_PAD_LEFT));
    }
    expect($fade->suggestions($this->family->id))->toBe([]);
    doneOn($a, '2026-10-30', 'rejected');
    expect($fade->suggestions($this->family->id))->toBe([]);
    doneOn($a, '2026-10-31', 'approved');

    expect($fade->suggestions($this->family->id)[0])->toMatchArray(['task_id' => $task->id, 'member' => 'Lea', 'confirmed' => 30]);
    expect($fade->apply())->toBe(0)->and($task->refresh()->star_reward)->toBe(4);
    expect(fn () => $fade->start($this->child, $task))->toThrow(GameException::class);
});

it('senkt die Sterne alle zwei Wochen um ein Viertel; Ehrensache bringt Abzeichen und Familien-Ehre', function () {
    Queue::fake();
    $task = makeTask($this->parent, ['type' => 'todo', 'scope' => 'family', 'title' => 'Spülmaschine', 'assignees' => [$this->child->id],
        'assignment_mode' => 'assigned', 'approval_mode' => 'none', 'star_reward' => 4]);
    $fade = app(StarFadeService::class);
    DeviceToken::create(['user_id' => $this->child->id, 'token' => 'kind']);

    $fade->start($this->parent, $task); // Montag 2.11. → ab Montag 9.11.
    expect($task->refresh()->star_fade_from->toDateString())->toBe('2026-11-09');
    Queue::assertPushed(SendPushNotification::class);

    $steps = ['2026-11-08' => 4, '2026-11-09' => 3, '2026-11-22' => 3, '2026-11-23' => 2, '2026-12-07' => 1, '2026-12-21' => 0, '2027-02-01' => 0];
    foreach ($steps as $day => $stars) {
        $this->travelTo(CarbonImmutable::parse("{$day} 10:00", 'Europe/Berlin'));
        $fade->apply();
        expect($task->refresh()->star_reward)->toBe($stars, "am {$day}");
    }

    expect($task->isHonor())->toBeTrue()
        ->and(UserBadge::where('user_id', $this->child->id)->where('kind', 'reliable')->count())->toBe(1);

    // Ehrensache erledigt: Danke + Ehre fürs Bauprojekt, ohne Gold abzuziehen
    $build = BuildProject::create(['family_id' => $this->family->id, 'project_key' => 'kitchen', 'status' => 'collecting',
        'requirements' => ['items' => [], 'gold' => 50], 'progress' => ['items' => [], 'gold' => 0]]);
    $gold = $this->child->stats()->first()->gold;
    app(ScoreTask::class)(assignmentOf($task, $this->child), 'up');

    expect($build->refresh()->progress['gold'])->toBe(StarFadeService::HONOR_POINTS)
        ->and(BuildContribution::where('item_key', 'honor')->count())->toBe(1)
        ->and($this->child->stats()->first()->gold)->toBeGreaterThanOrEqual($gold);

    // Eltern können zurücknehmen
    $fade->stop($this->parent, $task);
    expect($task->refresh()->star_reward)->toBe(4)->and($task->star_fade_base)->toBeNull();
});

it('Meistern, Erinnerung und Ehrensache über die API', function () {
    $task = makeTask($this->child, ['type' => 'daily', 'title' => 'Lesen', 'repeat' => ['type' => 'daily'], 'remind_at' => '18:00', 'anchor' => 'nach dem Abendessen']);
    $task->update(['mastery_offered_at' => now()]);
    Sanctum::actingAs($this->child, ['app']);

    $this->getJson('/api/v1/board')->assertJsonPath('dailies.0.anchor', 'nach dem Abendessen')->assertJsonPath('dailies.0.remind_at', '18:00')
        ->assertJsonPath('mastery.offers.0.title', 'Lesen');
    $this->postJson("/api/v1/tasks/{$task->id}/reminder/snooze", ['minutes' => 60])->assertOk()->assertJsonStructure(['snoozed_until']);
    $this->postJson("/api/v1/tasks/{$task->id}/reminder/feedback", ['action' => 'other_time', 'value' => '19:30'])->assertOk()->assertJsonPath('remind_at', '19:30');
    $this->postJson("/api/v1/tasks/{$task->id}/master")->assertOk();
    $this->getJson('/api/v1/mastery')->assertJsonPath('mastered.0.title', 'Lesen');
    $this->postJson("/api/v1/tasks/{$task->id}/fade-stars")->assertStatus(422);
});

it('zeigt Abzeichen in der Trophäenhalle', function () {
    app(BadgeService::class)->award($this->child, 'goal:1', 'goal', 'Fahrrad', '🚲');
    $home = Home::firstOrCreate(['family_id' => $this->family->id]);

    $trophies = HomeService::trophies($home);
    expect($trophies === null || $trophies['badges'][0]['name'] === 'Fahrrad')->toBeTrue();
});

it('liefert Eltern alle Familienaufgaben über die API, Kindern nicht', function () {
    makeTask($this->parent, ['type' => 'todo', 'scope' => 'family', 'title' => 'Spülmaschine', 'assignees' => [$this->child->id],
        'assignment_mode' => 'assigned', 'approval_mode' => 'none', 'star_reward' => 2]);
    makeTask($this->child, ['type' => 'todo', 'title' => 'Eigenes']);

    Sanctum::actingAs($this->parent, ['app']);
    $this->getJson('/api/v1/family/tasks')->assertOk()->assertJsonCount(1, 'tasks')->assertJsonPath('tasks.0.title', 'Spülmaschine')
        ->assertJsonPath('tasks.0.star_fade', null);

    Sanctum::actingAs($this->child, ['app']);
    $this->getJson('/api/v1/family/tasks')->assertForbidden();
});
