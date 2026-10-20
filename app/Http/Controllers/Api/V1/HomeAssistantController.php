<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Art\AvatarSvg;
use App\Domain\Art\MonsterSvg;
use App\Domain\Gameplay\GameException;
use App\Domain\Gameplay\ScoreTask;
use App\Domain\Quests\QuestService;
use App\Enums\CompletionStatus;
use App\Enums\TaskType;
use App\Http\Controllers\Controller;
use App\Http\Presenters\CharacterSheet;
use App\Http\Presenters\GamePresenter;
use App\Models\Item;
use App\Models\Quest;
use App\Models\RewardRedemption;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskCompletion;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Home-Assistant-Schnittstelle (Konzept §4). HA fragt den Zustand ab und sendet Ereignis-Schlüssel;
 * Eltern legen an der Aufgabe fest, welches Ereignis sie erledigt.
 */
class HomeAssistantController extends Controller
{
    public function state(Request $request, QuestService $quests): JsonResponse
    {
        $family = $request->user()->family;
        $inFamily = fn ($q) => $q->where('family_id', $family->id);

        $pendingCompletions = TaskCompletion::where('status', CompletionStatus::Pending)->whereHas('user', $inFamily)
            ->selectRaw('user_id, count(*) as n')->groupBy('user_id')->pluck('n', 'user_id');
        $pendingRedemptions = RewardRedemption::where('status', 'pending')->whereHas('user', $inFamily)->count();

        return response()->json([
            'family' => $family->name,
            'pending_approvals' => [
                'completions' => (int) $pendingCompletions->sum(),
                'redemptions' => $pendingRedemptions,
            ],
            'quest' => $this->quest($quests->activeFamily($family->id)),
            'members' => $family->members()->with('stats')->orderBy('name')->get()->map(function (User $m) use ($pendingCompletions) {
                $today = $m->gameDate();
                $assignments = TaskAssignment::with('task')->where('user_id', $m->id)
                    ->whereHas('task', fn ($q) => $q->whereIn('type', [TaskType::Daily, TaskType::Todo])->whereNull('archived_at'))
                    ->get();
                $dailies = $assignments->filter(fn (TaskAssignment $a) => $a->task->type === TaskType::Daily && $a->task->isDueFor($today, $a->user_id));
                $openTodos = $assignments->filter(fn (TaskAssignment $a) => $a->task->type === TaskType::Todo && ! $a->completed);

                return [
                    'id' => $m->id,
                    'name' => $m->name,
                    'role' => $m->family_role?->value,
                    'stats' => $m->stats ? GamePresenter::stats($m->stats) : null,
                    'dailies_due' => $dailies->count(),
                    'dailies_done' => $dailies->where('completed', true)->count(),
                    'all_dailies_done' => $dailies->isNotEmpty() && $dailies->every(fn ($a) => $a->completed),
                    'open_todos' => $openTodos->count(),
                    'open_dailies' => $dailies->where('completed', false)->map(fn ($a) => $a->task->title)->values(),
                    'streak' => (int) $dailies->max('streak'),
                    'pending_approvals' => (int) ($pendingCompletions[$m->id] ?? 0),
                    'avatar_version' => substr(md5(json_encode(CharacterSheet::appearance($m))), 0, 12),
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
                ->first(fn (TaskAssignment $a) => ($task->type === TaskType::Habit || ! $a->completed)
                    && $task->isOnDuty($a->user_id, $a->user->gameDate()));

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

    /** Avatar mit sichtbarer Ausrüstung als SVG (für die image-Entität in HA). */
    public function avatar(Request $request): Response
    {
        $appearance = CharacterSheet::appearance($request->user());
        $equipment = collect($appearance['equipment'])->filter();
        $icons = Item::whereIn('key', $equipment->pluck('key'))->pluck('icon', 'key');
        $equipment = $equipment->map(fn ($e) => ['key' => (string) $e['key'], 'rarity' => (string) $e['rarity'], 'icon' => $icons[$e['key']] ?? null])->all();

        return $this->svg(AvatarSvg::render($appearance['avatar'], $equipment, $appearance['seed'], $request->boolean('portrait'), classIcon: $appearance['class_icon']));
    }

    /** Bild der laufenden Familien-Quest: der Boss bzw. das gesuchte Sammelobjekt. */
    public function questImage(Request $request, QuestService $quests): Response
    {
        $quest = $quests->activeFamily($request->user()->family_id);
        abort_if($quest === null, 404, 'Keine laufende Familien-Quest.');

        if ($quest->isBoss()) {
            return $this->svg(MonsterSvg::render(QuestService::catalog()[$quest->quest_key]['art'] ?? null, $quest->remaining() <= 0));
        }

        $icon = e($quest->data['item_icon'] ?? $quest->icon ?? '⭐');

        return $this->svg('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 160 150">'
            .'<ellipse cx="80" cy="140" rx="46" ry="6" fill="#000" opacity=".15"/>'
            .'<path d="M28 70 h104 l-12 64 h-80 Z" fill="#d97706" stroke="#1f2937" stroke-width="3.5" stroke-linejoin="round"/>'
            .'<path d="M40 70 Q80 10 120 70" fill="none" stroke="#1f2937" stroke-width="3.5"/>'
            ."<text x=\"80\" y=\"80\" font-size=\"52\" text-anchor=\"middle\">{$icon}</text></svg>");
    }

    private function quest(?Quest $quest): ?array
    {
        if ($quest === null) {
            return null;
        }

        return [
            'id' => $quest->id,
            'name' => $quest->name,
            'type' => $quest->type,
            'icon' => $quest->icon,
            'goal' => $quest->goal,
            'progress' => round($quest->progress, 1),
            'remaining' => round($quest->remaining(), 1),
            'ends_at' => $quest->ends_at?->toIso8601String(),
        ];
    }

    private function svg(string $svg): Response
    {
        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'private, max-age=60',
        ]);
    }
}
