<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Gameplay\GameException;
use App\Domain\Gameplay\ScoreTask;
use App\Enums\TaskType;
use App\Http\Controllers\Controller;
use App\Http\Presenters\GamePresenter;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Home-Assistant-Schnittstelle (Konzept §4). HA fragt den Zustand ab und sendet Ereignis-Schlüssel;
 * Eltern legen an der Aufgabe fest, welches Ereignis sie erledigt.
 */
class HomeAssistantController extends Controller
{
    public function state(Request $request): JsonResponse
    {
        $family = $request->user()->family;

        return response()->json([
            'family' => $family->name,
            'members' => $family->members()->with('stats')->orderBy('name')->get()->map(function (User $m) {
                $today = $m->gameDate();
                $dailies = TaskAssignment::with('task')->where('user_id', $m->id)
                    ->whereHas('task', fn ($q) => $q->where('type', TaskType::Daily)->whereNull('archived_at'))
                    ->get()
                    ->filter(fn (TaskAssignment $a) => $a->task->isDueOn($today));
                $openTodos = TaskAssignment::where('user_id', $m->id)->where('completed', false)
                    ->whereHas('task', fn ($q) => $q->where('type', TaskType::Todo)->whereNull('archived_at'))
                    ->count();

                return [
                    'id' => $m->id,
                    'name' => $m->name,
                    'role' => $m->family_role?->value,
                    'stats' => $m->stats ? GamePresenter::stats($m->stats) : null,
                    'dailies_due' => $dailies->count(),
                    'dailies_done' => $dailies->where('completed', true)->count(),
                    'all_dailies_done' => $dailies->isNotEmpty() && $dailies->every(fn ($a) => $a->completed),
                    'open_todos' => $openTodos,
                    'open_dailies' => $dailies->where('completed', false)->map(fn ($a) => $a->task->title)->values(),
                ];
            }),
        ]);
    }

    /** Ereignis aus HA (z. B. NFC-Tag „nfc_feed_cat“) erledigt passende Familienaufgaben – verifiziert. */
    public function event(Request $request, ScoreTask $score): JsonResponse
    {
        $data = $request->validate([
            'event' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/'],
            'user_id' => ['nullable', 'integer'],
        ]);

        $tasks = Task::with('assignments')
            ->where('family_id', $request->user()->family_id)
            ->where('ha_event_key', $data['event'])
            ->whereIn('type', [TaskType::Daily->value, TaskType::Todo->value, TaskType::Habit->value])
            ->active()
            ->get();

        $handled = [];
        foreach ($tasks as $task) {
            $assignment = $task->assignments
                ->when($data['user_id'] ?? null, fn ($c, $id) => $c->where('user_id', $id))
                ->first(fn (TaskAssignment $a) => $task->type === TaskType::Habit || ! $a->completed);

            if ($assignment === null) {
                continue;
            }

            try {
                $result = $score($assignment, 'up', 'ha');
                $handled[] = ['task' => $task->title, 'user' => $assignment->user->name, 'xp' => $result->completion->xp, 'stars' => $result->completion->stars];
            } catch (GameException) {
                // bereits erledigt o. Ä. – Ereignis ignorieren
            }
        }

        return response()->json(['event' => $data['event'], 'handled' => $handled]);
    }
}
