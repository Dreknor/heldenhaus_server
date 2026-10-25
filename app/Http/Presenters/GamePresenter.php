<?php

namespace App\Http\Presenters;

use App\Domain\Gameplay\Formulas;
use App\Domain\Gameplay\ScoreTask;
use App\Domain\Support\StepService;
use App\Enums\AgeGroup;
use App\Enums\CompletionStatus;
use App\Enums\TaskType;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\User;
use App\Models\UserStats;

/** Bereitet Modelle als schlanke Arrays für Inertia und API auf. */
class GamePresenter
{
    public static function stats(UserStats $stats): array
    {
        return [
            'hp' => round($stats->hp, 1),
            'max_hp' => Formulas::maxHp($stats),
            'mp' => round($stats->mp, 1),
            'max_mp' => Formulas::maxMp($stats),
            'xp' => $stats->xp,
            'xp_to_next' => Formulas::xpToNextLevel($stats->level),
            'level' => $stats->level,
            'gold' => round($stats->gold, 2),
            'stars' => $stats->stars,
            'unspent_points' => $stats->unspent_points,
            'streak_shields' => $stats->streak_shields,
            'sleeping' => $stats->sleeping,
            'class_key' => $stats->class_key,
        ];
    }

    public static function member(User $user, bool $withStats = true): array
    {
        return array_filter([
            'id' => $user->id,
            'name' => $user->name,
            'role' => $user->family_role?->value,
            'role_label' => $user->family_role?->label(),
            'age_group' => $user->age_group,
            'birth_year' => $user->birth_year,
            'avatar_color' => $user->avatar_color,
            'has_email' => $user->email !== null,
            'chat_allowed' => $user->ageGroup() === AgeGroup::Teen ? (bool) $user->chat_allowed : null,
            'stats' => $withStats && $user->stats ? self::stats($user->stats) : null,
            'appearance' => $withStats ? CharacterSheet::appearance($user) : null,
        ], fn ($v) => $v !== null);
    }

    public static function assignment(TaskAssignment $a, ?\DateTimeInterface $today = null): array
    {
        $task = $a->task;
        $pending = $task->isFamilyTask() && $a->completed
            && $a->completions->first()?->status === CompletionStatus::Pending;

        return [
            ...self::task($task),
            'assignment_id' => $a->id,
            'value' => round($a->value, 2),
            'color' => Formulas::color($a->value),
            'streak' => $a->streak,
            'streak_paused' => $a->streak_paused_at !== null,
            'streak_best' => $a->streak_best,
            'steps' => $task->checklist && $task->supportsSteps() ? StepService::present($a) : [],
            'completed' => $a->completed,
            'counter_up' => $a->counter_up,
            'counter_down' => $a->counter_down,
            // Gewohnheiten: heute gezählt und was die nächste Wiederholung noch wert ist (1 = volle Belohnung, 0 = Limit erreicht)
            'habit_today' => $task->type === TaskType::Habit ? self::habitToday($a) : null,
            'due_today' => $task->type === TaskType::Daily ? $task->isDueFor($today ?? now(), $a->user_id) : null,
            'on_duty' => $task->assignment_mode === 'rotation' ? $task->isOnDuty($a->user_id, $today ?? now()) : null,
            'team' => $task->assignment_mode === 'team' && $task->relationLoaded('assignments')
                ? ['done' => $task->assignments->where('completed', true)->count(), 'total' => $task->assignments->count()]
                : null,
            'pending_approval' => $pending,
        ];
    }

    private static function habitToday(TaskAssignment $a): array
    {
        $up = ScoreTask::habitToday($a, 'up');
        $down = ScoreTask::habitToday($a, 'down');

        return ['up' => $up['today'], 'down' => $down['today'], 'limit' => $up['limit'], 'next_factor' => $up['limited'] ? 0.0 : $up['factor']];
    }

    public static function task(Task $task): array
    {
        return [
            'id' => $task->id,
            'type' => $task->type->value,
            'status' => $task->status,
            'is_core' => $task->is_core,
            'scope' => $task->scope,
            'title' => $task->title,
            'notes' => $task->notes,
            'category' => $task->category,
            'difficulty' => $task->difficulty,
            'up' => $task->up,
            'down' => $task->down,
            'daily_limit' => $task->type === TaskType::Habit ? (int) ($task->daily_limit ?? config('game.habits.daily_limit')) : null,
            'repeat' => $task->repeat,
            'start_date' => $task->start_date?->toDateString(),
            'due_date' => $task->due_date?->toDateString(),
            'checklist' => $task->supportsSteps() ? ($task->checklist ?? []) : [],
            'is_private' => $task->is_private,
            'approval_mode' => $task->approval_mode->value,
            'assignment_mode' => $task->assignment_mode,
            'rotation' => $task->rotation,
            'team_bonus_gold' => $task->team_bonus_gold,
            'star_reward' => $task->star_reward,
            'gold_cost' => $task->gold_cost,
            'ha_event_key' => $task->ha_event_key,
            'assignees' => $task->relationLoaded('assignments') ? $task->assignments->pluck('user_id')->all() : [],
        ];
    }
}
