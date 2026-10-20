<?php

namespace App\Domain\Gameplay;

use App\Domain\Classes\ModifierChain;
use App\Domain\Classes\ScoreContext;
use App\Domain\Inventory\DropService;
use App\Domain\Quests\QuestService;
use App\Domain\Social\FeedService;
use App\Domain\Support\StepService;
use App\Domain\Support\SupportService;
use App\Domain\Support\SurpriseService;
use App\Enums\ApprovalMode;
use App\Enums\CompletionStatus;
use App\Enums\TaskType;
use App\Events\LevelUp;
use App\Events\TaskScored;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskCompletion;
use App\Models\User;
use App\Models\UserStats;
use Illuminate\Support\Facades\DB;
use Random\Randomizer;

/**
 * Aufgabe abhaken (up), negative Gewohnheit / Rückgängig (down).
 * Wird von Web, API und Home Assistant gleichermaßen genutzt.
 */
class ScoreTask
{
    public function __construct(
        private readonly DropService $drops,
        private readonly QuestService $quests,
        private readonly FeedService $feed,
        private readonly Randomizer $random = new Randomizer,
        private ?SurpriseService $surprises = null,
    ) {
        $this->surprises ??= app(SurpriseService::class);
    }

    public function __invoke(
        TaskAssignment $assignment,
        string $direction,
        string $source = 'web',
        ?string $clientUuid = null,
    ): ScoreResult {
        if (! in_array($direction, ['up', 'down'], true)) {
            throw new GameException('Unbekannte Richtung.');
        }

        if ($clientUuid && ($existing = TaskCompletion::where('client_uuid', $clientUuid)->first())) {
            return new ScoreResult($existing, $assignment->user->stats()->first(), replayed: true);
        }

        $result = DB::transaction(function () use ($assignment, $direction, $source, $clientUuid) {
            /** @var TaskAssignment $assignment */
            $assignment = TaskAssignment::with('task.family', 'user')->lockForUpdate()->findOrFail($assignment->id);
            $stats = UserStats::lockForUpdate()->findOrFail($assignment->user_id);
            $task = $assignment->task;

            if ($task->archived_at !== null) {
                throw new GameException('Diese Aufgabe gibt es nicht mehr.');
            }

            return match ($task->type) {
                TaskType::Habit => $this->scoreHabit($assignment, $stats, $direction, $source, $clientUuid),
                TaskType::Daily, TaskType::Todo => $direction === 'up'
                    ? $this->complete($assignment, $stats, $source, $clientUuid)
                    : $this->undo($assignment, $stats),
                TaskType::Reward => throw new GameException('Belohnungen werden gekauft, nicht abgehakt.'),
            };
        });

        event(new TaskScored($result));

        if ($result->levelUps > 0) {
            event(new LevelUp($assignment->user, $result->stats->level));
        }

        return $result;
    }

    private function scoreHabit(TaskAssignment $a, UserStats $stats, string $direction, string $source, ?string $uuid): ScoreResult
    {
        $task = $a->task;

        if ($direction === 'up' && ! $task->up || $direction === 'down' && ! $task->down) {
            throw new GameException('Diese Gewohnheit kann man so nicht zählen.');
        }

        $valueBefore = $a->value;
        $delta = Formulas::delta($valueBefore);
        $levelUps = 0;
        $knockedOut = false;
        $xp = 0;
        $gold = 0.0;
        $hp = 0.0;

        if ($direction === 'up') {
            $a->value = Formulas::clampValue($valueBefore + $delta);
            $a->counter_up++;
            [$xp, $gold, $context] = $this->rewards($a, $stats, $delta, $source, 0);
            $levelUps = Progression::gainXp($stats, $xp);
            Progression::changeGold($stats, $gold);
        } else {
            $a->value = Formulas::clampValue($valueBefore - $delta);
            $a->counter_down++;
            $user = $a->user;
            $hp = Formulas::damage($delta, $task->difficulty, Attributes::for($stats)['con'], Formulas::ageDamageFactor($user->ageGroup(), $task->family ?? $user->family));
            $knockedOut = $stats->sleeping ? false : Progression::takeDamage($stats, $hp, $user->family);
            $hp = $stats->sleeping ? 0.0 : $hp;
        }

        $a->save();
        $stats->save();

        $completion = $this->record($a, $direction, $valueBefore, $delta, $xp, $gold, -$hp, $source, $uuid, $direction === 'up');

        $drop = null;
        if ($direction === 'up') {
            $this->afterPositive($completion, $a, $stats, $delta, $context);
            $drop = $this->drops->roll($completion, $task, $stats);
            $this->surprises->roll($completion, $task, $stats);
        }

        return new ScoreResult($completion, $stats, $levelUps, $knockedOut, drop: $drop);
    }

    private function complete(TaskAssignment $a, UserStats $stats, string $source, ?string $uuid): ScoreResult
    {
        $task = $a->task;

        if ($a->completed) {
            throw new GameException('Schon erledigt – super!');
        }

        // Ämterplan: nur wer dran ist, kann die Aufgabe abhaken
        $today = $a->user->gameDate();
        if (! $task->isOnDuty($a->user_id, $today)) {
            $duty = User::find($task->dutyUserId($today));
            throw new GameException("Heute ist {$duty?->name} mit „{$task->title}“ dran.");
        }

        $valueBefore = $a->value;
        $streakBefore = $a->streak;
        $delta = Formulas::delta($valueBefore);

        $a->value = Formulas::clampValue($valueBefore + $delta);
        $a->completed = true;
        $a->completed_at = now();

        // Pausierte Serie (§11.3): Serienbonus ruht, die Erledigung zählt für die Erholungs-Quest
        $paused = $task->type === TaskType::Daily && $a->streak_paused_at !== null;
        if ($paused) {
            SupportService::recoveryProgress($a);
        } elseif ($task->type === TaskType::Daily) {
            $a->streak++;
        }
        $a->streak_best = max($a->streak_best, $a->streak);

        [$xp, $gold, $context] = $this->rewards($a, $stats, $delta, $source, $task->type === TaskType::Daily && ! $paused ? $streakBefore : 0);

        // Schritt-Belohnung (§11.2): Was die Teilschritte schon gebracht haben, zieht der Abschluss ab
        [$stepXp, $stepGold, $stepShare] = StepService::paid($a);
        $xp = max(0, $xp - $stepXp);
        $gold = max(0.0, round($gold - $stepGold, 2));

        // Energiespar-Tag: Wer trotzdem eine Nicht-Kern-Daily schafft, bekommt einen kleinen Bonus
        if (SupportService::isExtraOnLowEnergy($a)) {
            $gold += (float) config('game.low_energy_bonus_gold');
            $context->effects[] = 'low_energy_bonus';
        }

        $levelUps = Progression::gainXp($stats, $xp);
        Progression::changeGold($stats, $gold);

        $a->save();
        $stats->save();

        $completion = $this->record($a, 'up', $valueBefore, $delta, $xp, $gold, 0, $source, $uuid, true, $streakBefore);

        if ($completion->status === CompletionStatus::Auto && $completion->stars > 0) {
            $stats->increment('stars', $completion->stars);
        }

        $this->afterPositive($completion, $a, $stats, $delta * (1 - $stepShare), $context);
        // Unsortierte Einträge der Kopf-Ablage geben keine Funde (§11.2)
        $drop = $task->status === 'inbox' ? null : $this->drops->roll($completion, $task, $stats);
        if ($task->type === TaskType::Daily) {
            $this->surprises->roll($completion, $task, $stats);
        }

        if ($task->isFamilyTask()) {
            if ($completion->status === CompletionStatus::Auto) {
                $this->feed->post($task->family_id, $a->user, 'task_done', '✅', "{$a->user->name} hat „{$task->title}“ erledigt");
            }
            $this->completeTeamIfDone($task);
        }

        return new ScoreResult($completion, $stats->refresh(), $levelUps, drop: $drop);
    }

    /** Teamaufgabe: Haben alle ihren Teil erledigt, gibt es einen Team-Bonus für alle. */
    private function completeTeamIfDone(Task $task): void
    {
        if ($task->assignment_mode !== 'team') {
            return;
        }

        $assignments = $task->assignments()->get();
        if ($assignments->isEmpty() || $assignments->contains('completed', false)) {
            return;
        }

        if ($task->team_bonus_gold > 0) {
            UserStats::whereIn('user_id', $assignments->pluck('user_id'))->increment('gold', $task->team_bonus_gold);
        }
        $this->feed->post($task->family_id, null, 'team_done', '🤝', "Teamarbeit! „{$task->title}“ ist komplett geschafft".($task->team_bonus_gold ? " – +{$task->team_bonus_gold} Gold für alle" : ''));
    }

    /**
     * XP und Gold eines positiven Abschlusses – mit wirksamen Attributen (inkl. Ausrüstung)
     * und der Modifikator-Kette aus Klassen-Passiven und Buffs.
     *
     * @return array{0: int, 1: float, 2: ScoreContext}
     */
    private function rewards(TaskAssignment $a, UserStats $stats, float $delta, string $source, int $streak): array
    {
        $attributes = Attributes::for($stats);
        $context = ModifierChain::applyScore(new ScoreContext($a->user, $stats, $a->task, $source, $this->random));

        $xp = (int) round(Formulas::xp($delta, $a->task->difficulty, $attributes['int']) * $context->xpMultiplier);
        $gold = round(Formulas::gold($delta, $a->task->difficulty, $attributes['per'], $streak) * $context->goldMultiplier + $context->goldFlat, 2);

        if ($context->mpGain > 0) {
            $stats->mp = min(Formulas::maxMp($stats), $stats->mp + $context->mpGain);
        }

        return [$xp, $gold, $context];
    }

    /** Boss-Schaden und Effekt-Protokoll nach einem positiven Abschluss. */
    private function afterPositive(TaskCompletion $completion, TaskAssignment $a, UserStats $stats, float $delta, ScoreContext $context): void
    {
        $stats->save();

        if ($context->effects) {
            $completion->forceFill(['effects' => array_values(array_unique($context->effects))])->save();
        }

        $this->quests->progress($completion, $a->user, $a->task, $delta, $context->bossMultiplier, Attributes::for($stats)['str']);
    }

    /** Letzten Abschluss zurücknehmen (Kind hat sich verklickt). */
    private function undo(TaskAssignment $a, UserStats $stats): ScoreResult
    {
        if (! $a->completed) {
            throw new GameException('Diese Aufgabe ist noch offen.');
        }

        $completion = $a->completions()
            ->where('direction', 'up')
            ->whereIn('status', [CompletionStatus::None, CompletionStatus::Pending, CompletionStatus::Approved, CompletionStatus::Auto])
            ->latest('id')
            ->first();

        if ($completion === null) {
            throw new GameException('Hier gibt es nichts rückgängig zu machen.');
        }

        self::reverse($a, $stats, $completion, CompletionStatus::Undone);

        return new ScoreResult($completion, $stats);
    }

    /** Effekte eines Abschlusses umkehren – für Rückgängig und Ablehnung durch Eltern. */
    public static function reverse(TaskAssignment $a, UserStats $stats, TaskCompletion $completion, CompletionStatus $newStatus, ?string $comment = null, ?int $reviewerId = null): void
    {
        DropService::revoke($completion);
        SurpriseService::revoke($completion);
        app(QuestService::class)->revert($completion);
        if ($completion->streak_before === $a->streak && $a->streak_paused_at !== null) {
            SupportService::recoveryProgress($a, -1);
        }

        $starsGranted = in_array($completion->status, [CompletionStatus::Approved, CompletionStatus::Auto], true) ? $completion->stars : 0;

        $a->value = $completion->value_before;
        $a->streak = $completion->streak_before;
        $a->completed = false;
        $a->completed_at = null;
        $a->save();

        Progression::loseXp($stats, $completion->xp);
        Progression::changeGold($stats, -$completion->gold);
        $stats->stars = max(0, $stats->stars - $starsGranted);
        $stats->save();

        $completion->forceFill([
            'status' => $newStatus,
            'review_comment' => $comment ?? $completion->review_comment,
            'reviewed_by' => $reviewerId ?? $completion->reviewed_by,
            'reviewed_at' => $reviewerId ? now() : $completion->reviewed_at,
        ])->save();
    }

    private function record(
        TaskAssignment $a,
        string $direction,
        float $valueBefore,
        float $delta,
        int $xp,
        float $gold,
        float $hp,
        string $source,
        ?string $uuid,
        bool $positive,
        ?int $streakBefore = null,
    ): TaskCompletion {
        $task = $a->task;
        $status = CompletionStatus::None;
        $stars = 0;

        if ($task->isFamilyTask() && $positive) {
            $stars = $task->star_reward;
            // Über Home Assistant erkannte Abschlüsse gelten als verifiziert.
            $needsApproval = $task->approval_mode !== ApprovalMode::None && $source !== 'ha';
            $status = $needsApproval ? CompletionStatus::Pending : CompletionStatus::Auto;
        }

        return TaskCompletion::create([
            'task_id' => $task->id,
            'assignment_id' => $a->id,
            'user_id' => $a->user_id,
            'direction' => $direction,
            'value_before' => $valueBefore,
            'delta' => $delta,
            'xp' => $xp,
            'gold' => $gold,
            'hp' => $hp,
            'stars' => $stars,
            'streak_before' => $streakBefore ?? $a->streak,
            'status' => $status,
            'source' => $source,
            'client_uuid' => $uuid,
        ]);
    }
}
