<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Gameplay\GameException;
use App\Domain\Goals\GoalService;
use App\Domain\Goals\MasteryService;
use App\Domain\Goals\ReminderService;
use App\Domain\Goals\StarFadeService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\GoalController as WebGoalController;
use App\Http\Requests\GoalRequest;
use App\Models\Goal;
use App\Models\GoalMilestone;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API v1 für Ziele (§14.1), Erinnerungen (§14.2), Meistern und Ehrensache (§14.3), wie in §14.12 beschrieben.
 */
class GoalController extends Controller
{
    public function __construct(
        private readonly GoalService $goals,
        private readonly ReminderService $reminders,
        private readonly MasteryService $mastery,
        private readonly StarFadeService $fade,
    ) {}

    /** Eigene Ziele (mit ?user_id= die eines Familienmitglieds), gemeinsame Familienziele und Vorlagen. */
    public function index(Request $request): JsonResponse
    {
        $viewer = $request->user();
        $owner = $request->integer('user_id') ? User::findOrFail($request->integer('user_id')) : $viewer;
        if (! $viewer->sameFamilyAs($owner) && $owner->id !== $viewer->id) {
            throw new GameException('Diese Person gehört nicht zu deiner Familie.');
        }

        return response()->json([
            ...$this->goals->forUser($owner, $viewer, withClosed: $request->boolean('all')),
            'family_goals' => GoalService::familyGoals($viewer),
            'templates' => GoalService::templates($owner),
            'tasks' => WebGoalController::linkableTasks($owner),
            'to_confirm' => $viewer->isParent() ? GoalService::pendingConfirmations($viewer) : [],
        ]);
    }

    public function store(GoalRequest $request): JsonResponse
    {
        $actor = $request->user();
        $owner = $request->integer('user_id') ? User::findOrFail($request->integer('user_id')) : $actor;
        $goal = $this->goals->create($actor, $owner, $request->validated());

        return response()->json($this->goals->present($goal->load('user', 'milestones', 'tasks', 'supporters'), $actor), 201);
    }

    public function update(GoalRequest $request, Goal $goal): JsonResponse
    {
        $goal = $this->goals->update($request->user(), $goal->load('user'), $request->validated());

        return response()->json($this->goals->present($goal->load('milestones', 'tasks', 'supporters'), $request->user()));
    }

    public function release(Request $request, Goal $goal): JsonResponse
    {
        $this->goals->release($request->user(), $goal->load('user'));

        return response()->json(['status' => 'released']);
    }

    public function count(Request $request, Goal $goal): JsonResponse
    {
        $data = $request->validate(['amount' => ['required', 'integer', 'between:-100,100']]);
        $goal = $this->goals->addCount($request->user(), $goal->load('user'), $data['amount']);

        return response()->json($this->goals->present($goal->load('user', 'milestones', 'tasks', 'supporters'), $request->user()));
    }

    public function milestone(Request $request, Goal $goal, GoalMilestone $milestone): JsonResponse
    {
        $result = $this->goals->completeMilestone($request->user(), $goal->load('user'), $milestone);

        return response()->json(['result' => $result, 'goal' => $this->goals->present($goal->refresh()->load('user', 'milestones', 'tasks', 'supporters'), $request->user())]);
    }

    public function declineMilestone(Request $request, Goal $goal, GoalMilestone $milestone): JsonResponse
    {
        $this->goals->declineMilestone($request->user(), $goal->load('user'), $milestone);

        return response()->json(['result' => 'declined']);
    }

    public function support(Request $request, Goal $goal): JsonResponse
    {
        $this->goals->support($request->user(), $goal->load('user'), ! $request->boolean('off'));

        return response()->json(['supporting' => ! $request->boolean('off')]);
    }

    public function templates(Request $request): JsonResponse
    {
        return response()->json(['templates' => GoalService::templates($request->user())]);
    }

    public function snooze(Request $request, Task $task): JsonResponse
    {
        $data = $request->validate(['minutes' => ['required', 'integer']]);
        $until = $this->reminders->snooze($request->user(), WebGoalController::assignment($request, $task), $data['minutes']);

        return response()->json(['snoozed_until' => $until->toIso8601String()]);
    }

    public function reminderFeedback(Request $request, Task $task): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:keep,other_time,other_anchor,off,pause'],
            'value' => ['nullable', 'string', 'max:80'],
        ]);
        $assignment = WebGoalController::assignment($request, $task);
        $this->reminders->feedback($request->user(), $assignment, $data['action'], $data['value'] ?? null);

        return response()->json(ReminderService::present($assignment->refresh()->load('task')));
    }

    public function mastery(Request $request): JsonResponse
    {
        return response()->json($this->mastery->cards($request->user()));
    }

    public function master(Request $request, Task $task): JsonResponse
    {
        $this->mastery->master($request->user(), $task);

        return response()->json(['mastered' => true]);
    }

    public function lessOften(Request $request, Task $task): JsonResponse
    {
        $this->mastery->lessOften($request->user(), $task);

        return response()->json(['type' => $task->refresh()->type->value]);
    }

    public function keep(Request $request, Task $task): JsonResponse
    {
        $this->mastery->keep($request->user(), $task);

        return response()->json(['kept' => true]);
    }

    public function stillOk(Request $request, Task $task): JsonResponse
    {
        $data = $request->validate(['ok' => ['required', 'boolean']]);
        $this->mastery->stillOk($request->user(), $task, $data['ok']);

        return response()->json(['mastered' => $task->refresh()->mastered_at !== null]);
    }

    public function fadeStars(Request $request, Task $task): JsonResponse
    {
        $action = $request->validate(['action' => ['nullable', 'in:start,dismiss,stop']])['action'] ?? 'start';
        match ($action) {
            'start' => $this->fade->start($request->user(), $task),
            'dismiss' => $this->fade->dismiss($request->user(), $task),
            'stop' => $this->fade->stop($request->user(), $task),
        };

        return response()->json(['star_fade' => StarFadeService::present($task->refresh()), 'star_reward' => $task->star_reward]);
    }

    public function fadeSuggestions(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(['suggestions' => $user->isParent() ? $this->fade->suggestions($user->family_id) : []]);
    }
}
