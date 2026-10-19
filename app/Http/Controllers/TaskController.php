<?php

namespace App\Http\Controllers;

use App\Domain\Gameplay\ScoreTask;
use App\Domain\Rewards\RewardService;
use App\Domain\Tasks\TaskService;
use App\Http\Requests\TaskRequest;
use App\Models\Task;
use App\Models\TaskAssignment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    public function __construct(private readonly TaskService $tasks) {}

    public function store(TaskRequest $request): RedirectResponse
    {
        $this->tasks->create($request->user(), $request->validated());

        return back()->with('success', 'Aufgabe angelegt.');
    }

    public function update(TaskRequest $request, Task $task): RedirectResponse
    {
        $this->tasks->update($task, $request->validated());

        return back()->with('success', 'Aufgabe gespeichert.');
    }

    public function destroy(Request $request, Task $task): RedirectResponse
    {
        $this->authorize('delete', $task);
        $this->tasks->archive($task);

        return back()->with('success', 'Aufgabe gelöscht.');
    }

    public function score(Request $request, TaskAssignment $assignment, string $direction, ScoreTask $score): RedirectResponse
    {
        abort_unless($assignment->user_id === $request->user()->id, 403);

        $result = $score($assignment, $direction, 'web', $request->input('client_uuid'));

        $redirect = back();
        if ($result->levelUps > 0) {
            $redirect->with('levelUp', $result->stats->level);
        }

        if ($result->drop) {
            $redirect->with('drop', $result->drop->only(['name', 'icon', 'rarity']));
        }

        if ($result->knockedOut) {
            $redirect->with('success', 'K.O.! Dein Held ruht sich kurz aus und ist wieder fit.');
        }

        return $redirect;
    }

    public function claim(Request $request, Task $task): RedirectResponse
    {
        $this->authorize('claim', $task);
        $this->tasks->claim($task, $request->user());

        return back()->with('success', 'Aufgabe übernommen – los geht\'s!');
    }

    public function buy(Request $request, Task $task, RewardService $rewards): RedirectResponse
    {
        $rewards->buyPersonal($task, $request->user());

        return back()->with('success', "Gönn dir: {$task->title}!");
    }
}
