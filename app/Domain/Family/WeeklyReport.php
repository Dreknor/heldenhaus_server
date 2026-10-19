<?php

namespace App\Domain\Family;

use App\Enums\CompletionStatus;
use App\Enums\FamilyRole;
use App\Models\DayLog;
use App\Models\Family;
use App\Models\Quest;
use App\Models\TaskCompletion;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Wochenbericht für Eltern (Konzept §3.5): Dailies-Quote, Familienaufgaben (Fairness-Verteilung),
 * Sterne, Quests und offene Freigaben einer Kalenderwoche.
 */
class WeeklyReport
{
    public static function for(Family $family, ?CarbonImmutable $weekStart = null): array
    {
        $start = ($weekStart ?? CarbonImmutable::now($family->timezone)->startOfWeek())->startOfDay();
        $end = $start->addWeek();

        $members = $family->members()->where('family_role', '!=', FamilyRole::Guest->value)->orderBy('name')->get();
        $counted = [CompletionStatus::None->value, CompletionStatus::Pending->value, CompletionStatus::Approved->value, CompletionStatus::Auto->value];

        $rows = $members->map(function (User $m) use ($start, $end, $counted) {
            $logs = DayLog::where('user_id', $m->id)->whereBetween('date', [$start->toDateString(), $end->subDay()->toDateString()])->get();
            $completions = TaskCompletion::with('task')->where('user_id', $m->id)->where('direction', 'up')
                ->whereIn('status', $counted)->whereBetween('created_at', [$start, $end])->get();

            return [
                'id' => $m->id,
                'name' => $m->name,
                'avatar_color' => $m->avatar_color,
                'dailies_due' => (int) $logs->sum('dailies_due'),
                'dailies_done' => (int) $logs->sum('dailies_done'),
                'tasks_done' => $completions->count(),
                'family_tasks_done' => $completions->filter(fn ($c) => $c->task?->isFamilyTask())->count(),
                'stars' => (int) $completions->whereIn('status', [CompletionStatus::Approved, CompletionStatus::Auto])->sum('stars'),
                'xp' => (int) $completions->sum('xp'),
                'quest_progress' => round((float) $completions->sum('quest_progress'), 1),
                'knocked_out' => $logs->where('knocked_out', true)->count(),
            ];
        });

        $familyTotal = max(1, $rows->sum('family_tasks_done'));

        return [
            'family' => $family->name,
            'week_start' => $start->toDateString(),
            'week_end' => $end->subDay()->toDateString(),
            'members' => $rows->map(fn ($r) => [...$r, 'family_share' => round($r['family_tasks_done'] / $familyTotal * 100)])->values()->all(),
            'quests' => Quest::where('family_id', $family->id)->where('scope', 'family')
                ->where(fn ($q) => $q->whereBetween('created_at', [$start, $end])->orWhereBetween('completed_at', [$start, $end]))
                ->get(['name', 'icon', 'status', 'progress', 'goal'])->toArray(),
            'pending_approvals' => TaskCompletion::where('status', CompletionStatus::Pending)
                ->whereIn('user_id', $members->pluck('id'))->count(),
        ];
    }
}
