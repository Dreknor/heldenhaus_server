<?php

namespace App\Http\Controllers;

use App\Domain\Gameplay\RunDayCron;
use App\Enums\CompletionStatus;
use App\Enums\TaskType;
use App\Http\Presenters\GamePresenter;
use App\Models\Task;
use App\Models\TaskAssignment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HeroController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $today = $user->gameDate();

        $assignments = TaskAssignment::with([
            'task',
            'completions' => fn ($q) => $q->where('direction', 'up')->latest('id')->limit(1),
        ])
            ->where('user_id', $user->id)
            ->whereHas('task', fn ($q) => $q->whereNull('archived_at'))
            ->get()
            ->sortBy(fn (TaskAssignment $a) => [$a->task->sort, $a->task->id])
            ->map(fn (TaskAssignment $a) => GamePresenter::assignment($a, $today))
            ->groupBy('type');

        // Erledigte To-dos nur kurz zeigen (heute erledigt)
        $todos = collect($assignments->get('todo', []))
            ->filter(fn ($t) => ! $t['completed'] || ($t['pending_approval'] ?? false))
            ->values();

        $pool = Task::where('family_id', $user->family_id)
            ->where('assignment_mode', 'pool')
            ->active()
            ->doesntHave('assignments')
            ->latest()
            ->get()
            ->map(fn (Task $t) => GamePresenter::task($t));

        $rewards = Task::where('owner_id', $user->id)
            ->where('type', TaskType::Reward)
            ->active()
            ->orderBy('gold_cost')
            ->get()
            ->map(fn (Task $t) => GamePresenter::task($t));

        $openYesterday = RunDayCron::openFromLastDay($user)
            ->map(fn (TaskAssignment $a) => ['assignment_id' => $a->id, 'title' => $a->task->title])
            ->values();

        return Inertia::render('Dashboard', [
            'habits' => $assignments->get('habit', collect())->values(),
            'dailies' => $assignments->get('daily', collect())->values(),
            'todos' => $todos,
            'rewards' => $rewards,
            'pool' => $pool,
            'cronPending' => $openYesterday,
            'categories' => config('game.categories'),
            'difficulties' => config('game.difficulties'),
            'recentPending' => $user->completions()->where('status', CompletionStatus::Pending)->count(),
        ]);
    }

    public function runCron(Request $request, RunDayCron $cron): RedirectResponse
    {
        $data = $request->validate([
            'done' => ['array'],
            'done.*' => ['integer'],
        ]);

        $log = $cron($request->user(), null, $data['done'] ?? []);

        $message = match (true) {
            $log === null => 'Ein neuer Tag beginnt!',
            $log->knocked_out => 'Uff – dein Held war K.O. und hat sich ausgeruht. Heute geht\'s neu los!',
            $log->damage > 0 => "Neuer Tag! Verpasste Aufgaben kosteten {$log->damage} HP.",
            default => 'Neuer Tag – gestern lief super!',
        };

        return to_route('dashboard')->with('success', $message);
    }
}
