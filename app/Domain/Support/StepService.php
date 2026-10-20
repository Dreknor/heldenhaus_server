<?php

namespace App\Domain\Support;

use App\Domain\Gameplay\Attributes;
use App\Domain\Gameplay\Formulas;
use App\Domain\Gameplay\GameException;
use App\Domain\Gameplay\Progression;
use App\Domain\Gameplay\ScoreTask;
use App\Domain\Integrations\HomeAssistant\HomeAssistantWebhooks;
use App\Domain\Quests\QuestService;
use App\Enums\CompletionStatus;
use App\Enums\TaskType;
use App\Events\LevelUp;
use App\Models\TaskAssignment;
use App\Models\TaskCompletion;
use App\Models\User;
use App\Models\UserStats;
use Illuminate\Support\Facades\DB;

/**
 * Mikro-Schritte (§11.2): Jeder Schritt einer Checkliste bringt sofort 1/n der Belohnung
 * (XP, Gold, Boss-Schaden). Der letzte Schritt schließt die Aufgabe ab; der Abschluss zieht ab,
 * was die Schritte schon gebracht haben – die Summe bleibt also gleich. Sterne gibt es nur für die ganze Aufgabe.
 */
class StepService
{
    public function __construct(private readonly ScoreTask $score, private readonly QuestService $quests) {}

    /** @return array{step: int, completion_id: int, xp: int, gold: float, final: bool, result: ?array} */
    public function score(TaskAssignment $assignment, int $index, string $source = 'app', ?string $uuid = null): array
    {
        if ($uuid && ($existing = TaskCompletion::where('client_uuid', $uuid)->first())) {
            return ['step' => $index, 'completion_id' => $existing->id, 'xp' => $existing->xp, 'gold' => $existing->gold, 'final' => $existing->direction === 'up', 'replayed' => true, 'result' => null];
        }

        $final = false;
        $step = DB::transaction(function () use ($assignment, $index, $source, $uuid, &$final) {
            $a = TaskAssignment::with('task', 'user')->lockForUpdate()->findOrFail($assignment->id);
            $task = $a->task;
            $steps = $task->checklist ?? [];
            $done = $a->checklist_done ?? [];

            if (! in_array($task->type, [TaskType::Daily, TaskType::Todo], true) || $task->archived_at !== null) {
                throw new GameException('Diese Aufgabe hat keine Schritte.');
            }
            if (! array_key_exists($index, $steps)) {
                throw new GameException('Diesen Schritt gibt es nicht.');
            }
            if ($a->completed) {
                throw new GameException('Schon erledigt – super!');
            }
            if (array_key_exists((string) $index, $done)) {
                throw new GameException('Dieser Schritt ist schon erledigt.');
            }
            if (! $task->isOnDuty($a->user_id, $a->user->gameDate())) {
                throw new GameException('Heute ist jemand anderes mit dieser Aufgabe dran.');
            }

            // Letzter offener Schritt → die Aufgabe selbst wird abgehakt
            if (count($done) >= count($steps) - 1) {
                $done[(string) $index] = 0;
                $a->forceFill(['checklist_done' => $done])->save();
                $final = true;

                return null;
            }

            $stats = UserStats::lockForUpdate()->findOrFail($a->user_id);
            $attributes = Attributes::for($stats);
            $n = count($steps);
            $delta = Formulas::delta($a->value);
            $xp = max(1, (int) round(Formulas::xp($delta, $task->difficulty, $attributes['int']) / $n));
            $gold = round(Formulas::gold($delta, $task->difficulty, $attributes['per'], 0) / $n, 2);

            $levelUps = Progression::gainXp($stats, $xp);
            Progression::changeGold($stats, $gold);
            $stats->save();

            $completion = TaskCompletion::create([
                'task_id' => $task->id,
                'assignment_id' => $a->id,
                'user_id' => $a->user_id,
                'direction' => 'step',
                'value_before' => $a->value,
                'delta' => $delta / $n,
                'xp' => $xp,
                'gold' => $gold,
                'status' => CompletionStatus::None,
                'source' => $source,
                'streak_before' => $a->streak,
                'client_uuid' => $uuid,
                'effects' => ['step:'.$index],
            ]);

            $this->quests->progress($completion, $a->user, $task, $delta / $n, 1.0, $attributes['str']);

            $done[(string) $index] = $completion->id;
            $a->forceFill(['checklist_done' => $done])->save();

            return [$completion, $levelUps, $a];
        });

        if ($final) {
            $result = ($this->score)($assignment->refresh(), 'up', $source, $uuid);

            return ['step' => $index, 'completion_id' => $result->completion->id, 'xp' => $result->completion->xp, 'gold' => $result->completion->gold, 'final' => true, 'replayed' => false, 'result' => $result->toArray()];
        }

        [$completion, $levelUps, $a] = $step;
        if ($levelUps > 0) {
            event(new LevelUp($a->user, $a->user->stats()->value('level')));
        }
        app(HomeAssistantWebhooks::class)->send($a->user->family_id, 'step_completed', HomeAssistantWebhooks::member($a->user) + [
            'task_id' => $a->task_id,
            'task_title' => $a->task->is_private ? null : $a->task->title,
            'step' => $index + 1,
            'steps' => count($a->task->checklist ?? []),
        ]);

        return ['step' => $index, 'completion_id' => $completion->id, 'xp' => $completion->xp, 'gold' => $completion->gold, 'final' => false, 'replayed' => false, 'result' => null];
    }

    /**
     * Was die Schritte seit dem letzten Zurücksetzen schon gebracht haben.
     *
     * @return array{0: int, 1: float, 2: float} XP, Gold, Anteil der erledigten Schritte
     */
    public static function paid(TaskAssignment $a): array
    {
        $done = $a->checklist_done ?? [];
        $steps = count($a->task->checklist ?? []);
        if ($done === [] || $steps === 0 || array_is_list($done) && ! array_filter($done)) {
            return [0, 0.0, 0.0];
        }

        $ids = array_filter(array_values($done));
        $paid = TaskCompletion::whereIn('id', $ids)->where('direction', 'step')->get();

        return [(int) $paid->sum('xp'), (float) $paid->sum('gold'), min(1.0, $paid->count() / $steps)];
    }

    /** Schritte für die Anzeige: einer nach dem anderen. */
    public static function present(TaskAssignment $a): array
    {
        $done = $a->checklist_done ?? [];

        return collect($a->task->checklist ?? [])->map(fn ($title, $i) => [
            'index' => $i,
            'title' => $title,
            'done' => $a->completed || array_key_exists((string) $i, $done),
        ])->values()->all();
    }

    public static function forUser(User $user, TaskAssignment $a): TaskAssignment
    {
        if ($a->user_id !== $user->id) {
            throw new GameException('Das ist nicht deine Aufgabe.');
        }

        return $a;
    }
}
