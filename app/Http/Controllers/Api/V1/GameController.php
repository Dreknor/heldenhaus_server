<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Gameplay\GameException;
use App\Domain\Gameplay\RunDayCron;
use App\Domain\Gameplay\ScoreTask;
use App\Domain\Rewards\RewardService;
use App\Domain\Tasks\TaskService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\HeroController;
use App\Http\Presenters\GamePresenter;
use App\Http\Presenters\HeroBoard;
use App\Http\Requests\TaskRequest;
use App\Models\Task;
use App\Models\TaskAssignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GameController extends Controller
{
    public function __construct(private readonly TaskService $tasks) {}

    /** Komplettes Heldenpanel – Grundlage für den Offline-Speicher der App. */
    public function board(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            ...HeroBoard::for($user),
            'stats' => GamePresenter::stats($user->stats()->first()),
            'appearance' => \App\Http\Presenters\CharacterSheet::appearance($user),
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function cron(Request $request, RunDayCron $cron): JsonResponse
    {
        $data = $request->validate(['done' => ['array'], 'done.*' => ['integer']]);
        $log = $cron($request->user(), null, $data['done'] ?? []);

        return response()->json([
            'message' => HeroController::cronMessage($log),
            'day' => $log?->only(['date', 'dailies_due', 'dailies_done', 'damage', 'knocked_out']),
            'stats' => GamePresenter::stats($request->user()->stats()->first()),
        ]);
    }

    public function score(Request $request, TaskAssignment $assignment, string $direction, ScoreTask $score): JsonResponse
    {
        abort_unless($assignment->user_id === $request->user()->id, 403);
        $data = $request->validate(['client_uuid' => ['nullable', 'uuid']]);

        return response()->json($score($assignment, $direction, 'app', $data['client_uuid'] ?? null)->toArray());
    }

    /**
     * Offline gesammelte Aktionen nachträglich übertragen. Jede Aktion trägt eine client_uuid,
     * doppelt gesendete Aktionen werden erkannt und nicht erneut gewertet.
     */
    public function sync(Request $request, ScoreTask $score): JsonResponse
    {
        $data = $request->validate([
            'actions' => ['required', 'array', 'max:200'],
            'actions.*.client_uuid' => ['required', 'uuid'],
            'actions.*.type' => ['required', 'in:score'],
            'actions.*.assignment_id' => ['required', 'integer'],
            'actions.*.direction' => ['required', 'in:up,down'],
        ]);

        $results = [];
        foreach ($data['actions'] as $action) {
            $assignment = TaskAssignment::where('user_id', $request->user()->id)->find($action['assignment_id']);

            if ($assignment === null) {
                $results[] = ['client_uuid' => $action['client_uuid'], 'ok' => false, 'message' => 'Aufgabe nicht gefunden.'];

                continue;
            }

            try {
                $result = $score($assignment, $action['direction'], 'app', $action['client_uuid']);
                $results[] = ['client_uuid' => $action['client_uuid'], 'ok' => true, 'replayed' => $result->replayed] + $result->toArray();
            } catch (GameException $e) {
                $results[] = ['client_uuid' => $action['client_uuid'], 'ok' => false, 'message' => $e->getMessage()];
            }
        }

        return response()->json([
            'results' => $results,
            'stats' => GamePresenter::stats($request->user()->stats()->first()),
        ]);
    }

    public function storeTask(TaskRequest $request): JsonResponse
    {
        $task = $this->tasks->create($request->user(), $request->validated());

        return response()->json(GamePresenter::task($task->load('assignments')), 201);
    }

    public function updateTask(TaskRequest $request, Task $task): JsonResponse
    {
        return response()->json(GamePresenter::task($this->tasks->update($task, $request->validated())->load('assignments')));
    }

    public function destroyTask(Request $request, Task $task): JsonResponse
    {
        $this->authorize('delete', $task);
        $this->tasks->archive($task);

        return response()->json(['ok' => true]);
    }

    public function claim(Request $request, Task $task): JsonResponse
    {
        $this->authorize('claim', $task);
        $assignment = $this->tasks->claim($task, $request->user());

        return response()->json(GamePresenter::assignment($assignment->load('task', 'completions')));
    }

    public function buy(Request $request, Task $task, RewardService $rewards): JsonResponse
    {
        return response()->json(['stats' => GamePresenter::stats($rewards->buyPersonal($task, $request->user()))]);
    }
}
