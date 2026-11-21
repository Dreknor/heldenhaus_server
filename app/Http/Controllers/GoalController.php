<?php

namespace App\Http\Controllers;

use App\Domain\Gameplay\GameException;
use App\Domain\Goals\GoalService;
use App\Domain\Goals\MasteryService;
use App\Domain\Goals\ReminderService;
use App\Domain\Goals\StarFadeService;
use App\Enums\TaskType;
use App\Http\Requests\GoalRequest;
use App\Models\Goal;
use App\Models\GoalMilestone;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Ziele (§14.1), Erinnerungen (§14.2), Meistern und Ehrensache (§14.3) im Web. */
class GoalController extends Controller
{
    public function __construct(
        private readonly GoalService $goals,
        private readonly ReminderService $reminders,
        private readonly MasteryService $mastery,
        private readonly StarFadeService $fade,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $members = User::where('family_id', $user->family_id)->where('id', '!=', $user->id)->orderBy('name')->get();

        return Inertia::render('Goals', [
            'mine' => $this->goals->forUser($user, $user, withClosed: true),
            'members' => $members->map(fn (User $m) => [
                'id' => $m->id,
                'name' => $m->name,
                'is_child' => $m->isChild(),
                ...$this->goals->forUser($m, $user),
                'tasks' => $user->isParent() ? self::linkableTasks($m) : [],
                'templates' => $user->isParent() ? GoalService::templates($m) : [],
            ])->values(),
            'family_goals' => GoalService::familyGoals($user),
            'templates' => GoalService::templates($user),
            'tasks' => self::linkableTasks($user),
            'to_confirm' => $user->isParent() ? GoalService::pendingConfirmations($user) : [],
            'can_private' => $user->ageGroup()->value !== 'child',
            'is_parent' => $user->isParent(),
        ]);
    }

    public function store(GoalRequest $request): RedirectResponse
    {
        $actor = $request->user();
        $owner = $request->integer('user_id') ? User::findOrFail($request->integer('user_id')) : $actor;
        $goal = $this->goals->create($actor, $owner, $request->validated());

        return back()->with('success', "Ziel „{$goal->title}“ gesetzt. Du schaffst das!");
    }

    public function update(GoalRequest $request, Goal $goal): RedirectResponse
    {
        $this->goals->update($request->user(), $goal->load('user'), $request->validated());

        return back()->with('success', 'Ziel angepasst.');
    }

    public function release(Request $request, Goal $goal): RedirectResponse
    {
        $this->goals->release($request->user(), $goal->load('user'));

        return back()->with('success', 'Losgelassen. Das ist völlig in Ordnung.');
    }

    public function count(Request $request, Goal $goal): RedirectResponse
    {
        $data = $request->validate(['amount' => ['required', 'integer', 'between:-100,100']]);
        $goal = $this->goals->addCount($request->user(), $goal->load('user'), $data['amount']);

        return back()->with('success', $goal->status === 'reached' ? 'Ziel erreicht! 🏆' : 'Gezählt!');
    }

    public function milestone(Request $request, Goal $goal, GoalMilestone $milestone): RedirectResponse
    {
        $result = $this->goals->completeMilestone($request->user(), $goal->load('user'), $milestone);

        return back()->with('success', $result === 'requested' ? 'Super! Deine Eltern bestätigen den Schritt noch.' : 'Meilenstein geschafft! 🚩');
    }

    public function declineMilestone(Request $request, Goal $goal, GoalMilestone $milestone): RedirectResponse
    {
        $this->goals->declineMilestone($request->user(), $goal->load('user'), $milestone);

        return back()->with('success', 'Zurück an das Kind – vielleicht klappt es beim nächsten Mal.');
    }

    public function support(Request $request, Goal $goal): RedirectResponse
    {
        $this->goals->support($request->user(), $goal->load('user'), ! $request->boolean('off'));

        return back()->with('success', $request->boolean('off') ? 'Unterstützung beendet.' : 'Danke, dass du hilfst! 🤝');
    }

    // ---------- Erinnerungen ----------

    public function snooze(Request $request, Task $task): RedirectResponse
    {
        $data = $request->validate(['minutes' => ['required', 'integer']]);
        $until = $this->reminders->snooze($request->user(), self::assignment($request, $task), $data['minutes']);

        return back()->with('success', "Ich erinnere dich um {$until->setTimezone($request->user()->timezone ?: 'Europe/Berlin')->format('H:i')} Uhr nochmal.");
    }

    public function reminderFeedback(Request $request, Task $task): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:keep,other_time,other_anchor,off,pause'],
            'value' => ['nullable', 'string', 'max:80'],
        ]);
        $this->reminders->feedback($request->user(), self::assignment($request, $task), $data['action'], $data['value'] ?? null);

        return back()->with('success', 'Alles klar, ist angepasst.');
    }

    // ---------- Meistern ----------

    public function master(Request $request, Task $task): RedirectResponse
    {
        $this->mastery->master($request->user(), $task);

        return back()->with('success', 'Gemeistert! 🎖️ Das sitzt jetzt.');
    }

    public function lessOften(Request $request, Task $task): RedirectResponse
    {
        $this->mastery->lessOften($request->user(), $task);

        return back()->with('success', 'Ab jetzt reicht 5 von 7 – als Wochenziel.');
    }

    public function keep(Request $request, Task $task): RedirectResponse
    {
        $this->mastery->keep($request->user(), $task);

        return back()->with('success', 'Alles bleibt, wie es ist.');
    }

    public function stillOk(Request $request, Task $task): RedirectResponse
    {
        $this->mastery->stillOk($request->user(), $task, $request->boolean('ok'));

        return back()->with('success', $request->boolean('ok') ? 'Super, weiter so!' : 'Die Aufgabe ist wieder auf deiner Liste.');
    }

    // ---------- Ehrensache (Eltern) ----------

    public function fadeStars(Request $request, Task $task): RedirectResponse
    {
        $action = $request->validate(['action' => ['required', 'in:start,dismiss,stop']])['action'];
        match ($action) {
            'start' => $this->fade->start($request->user(), $task),
            'dismiss' => $this->fade->dismiss($request->user(), $task),
            'stop' => $this->fade->stop($request->user(), $task),
        };

        return back()->with('success', match ($action) {
            'start' => 'Die Sterne werden ab nächster Woche langsam weniger. Die Kinder wissen Bescheid.',
            'dismiss' => 'Vorschlag ausgeblendet.',
            'stop' => 'Die ursprünglichen Sterne gelten wieder.',
        });
    }

    /** Aufgabe der Person (oder als Elternteil: eines Familienmitglieds über member_id). */
    public static function assignment(Request $request, Task $task): TaskAssignment
    {
        $user = $request->user();
        $memberId = $user->isParent() && $request->integer('member_id') ? $request->integer('member_id') : $user->id;
        $assignment = TaskAssignment::with('task', 'user')->where('task_id', $task->id)->where('user_id', $memberId)->first();
        if ($assignment === null || ! $user->sameFamilyAs($assignment->user) && $assignment->user_id !== $user->id) {
            throw new GameException('Diese Aufgabe gehört nicht zu dir.');
        }

        return $assignment;
    }

    /** Aufgaben, die man mit einem Ziel verknüpfen kann. */
    public static function linkableTasks(User $user): array
    {
        return Task::whereHas('assignments', fn ($q) => $q->where('user_id', $user->id))->whereNull('archived_at')
            ->whereIn('type', [TaskType::Daily, TaskType::Habit, TaskType::Todo])->orderBy('title')->get()
            ->map(fn (Task $t) => ['id' => $t->id, 'title' => $t->title, 'type' => $t->type->value])->values()->all();
    }
}
