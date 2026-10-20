<?php

namespace App\Domain\Stats;

use App\Domain\Achievements\AchievementService;
use App\Enums\CompletionStatus;
use App\Enums\FamilyRole;
use App\Models\CraftJob;
use App\Models\DayLog;
use App\Models\Dungeon;
use App\Models\DungeonAction;
use App\Models\Family;
use App\Models\GardenPlot;
use App\Models\TaskAssignment;
use App\Models\TaskCompletion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Statistiken (Phase 8): Verlauf, Kategorien, Wochentage, Serien und Spielwerte – für eine Person und die Familie.
 * Gerechnet wird in PHP über ein begrenztes Zeitfenster, damit es auf MySQL und SQLite gleich aussieht.
 */
class StatsService
{
    public const WEEKS = 12;

    public const DAYS = 90;

    public static function forUser(User $user): array
    {
        $today = $user->gameDate();
        $completions = self::completions(collect([$user->id]), $today->subDays(self::DAYS));
        $achievements = app(AchievementService::class);

        $days = DayLog::where('user_id', $user->id)->where('date', '>=', $today->subDays(30)->toDateString())->get();

        return [
            'period' => ['from' => $today->subDays(self::DAYS)->toDateString(), 'to' => $today->toDateString()],
            'weeks' => self::weekly($completions, $today),
            'categories' => self::categories($completions->where('created_at', '>=', $today->subDays(30))),
            'weekdays' => self::weekdays($completions, $user),
            'hours' => self::hours($completions, $user),
            'last_30_days' => [
                'tasks' => $completions->where('created_at', '>=', $today->subDays(30))->count(),
                'xp' => (int) $completions->where('created_at', '>=', $today->subDays(30))->sum('xp'),
                'gold' => round((float) $completions->where('created_at', '>=', $today->subDays(30))->sum('gold'), 1),
                'perfect_days' => $days->filter(fn ($d) => $d->dailies_due > 0 && $d->dailies_done >= $d->dailies_due)->count(),
                'dailies_rate' => $days->sum('dailies_due') > 0 ? round($days->sum('dailies_done') / $days->sum('dailies_due'), 2) : null,
            ],
            'streaks' => TaskAssignment::with('task:id,title,type')->where('user_id', $user->id)->where('streak', '>', 0)
                ->orderByDesc('streak')->limit(5)->get()
                ->map(fn (TaskAssignment $a) => ['task' => $a->task?->title, 'type' => $a->task?->type, 'streak' => $a->streak])->values(),
            'totals' => [
                'tasks_done' => $achievements->metric($user, 'tasks_done'),
                'habits_up' => $achievements->metric($user, 'habits_up'),
                'best_streak' => $achievements->metric($user, 'best_streak'),
                'bosses_defeated' => $achievements->metric($user, 'bosses_defeated'),
                'items_crafted' => $achievements->metric($user, 'items_crafted'),
                'dungeons_cleared' => $achievements->metric($user, 'dungeons_cleared'),
                'monsters_defeated' => $achievements->metric($user, 'monsters_defeated'),
                'harvests' => $achievements->metric($user, 'harvests'),
                'pets' => $achievements->metric($user, 'pets'),
            ],
        ];
    }

    public static function forFamily(Family $family, User $viewer): array
    {
        $today = $viewer->gameDate();
        $members = User::where('family_id', $family->id)->where('family_role', '!=', FamilyRole::Guest)->orderBy('name')->get();
        $completions = self::completions($members->pluck('id'), $today->subWeeks(4)->startOfWeek());

        return [
            'weeks' => self::weekly($completions, $today, 4),
            'categories' => self::categories($completions),
            'members' => $members->map(fn (User $m) => [
                'user_id' => $m->id,
                'name' => $m->name,
                'weeks' => collect(self::weekly($completions->where('user_id', $m->id), $today, 4))->pluck('tasks'),
                'tasks_4_weeks' => $completions->where('user_id', $m->id)->count(),
            ])->values(),
            'totals' => [
                'tasks_4_weeks' => $completions->count(),
                'family_tasks_4_weeks' => $completions->where('scope', 'family')->count(),
                'dungeons_cleared' => Dungeon::where('family_id', $family->id)->where('kind', 'family')->where('status', 'cleared')->count(),
                'items_crafted' => CraftJob::whereIn('user_id', $members->pluck('id'))->where('status', 'collected')->sum('quantity'),
            ],
        ];
    }

    /** Bestätigte bzw. freie Abschlüsse (ohne Abgelehnte/Rückgängige) mit Kategorie. */
    private static function completions(Collection $userIds, CarbonImmutable $since): Collection
    {
        return TaskCompletion::query()
            ->join('tasks', 'tasks.id', '=', 'task_completions.task_id')
            ->whereIn('task_completions.user_id', $userIds)
            ->where('task_completions.direction', 'up')
            ->whereIn('task_completions.status', [CompletionStatus::None, CompletionStatus::Approved, CompletionStatus::Auto])
            ->where('task_completions.created_at', '>=', $since->utc())
            ->get(['task_completions.user_id', 'task_completions.xp', 'task_completions.gold', 'task_completions.created_at', 'tasks.category', 'tasks.type', 'tasks.scope']);
    }

    private static function weekly(Collection $completions, CarbonImmutable $today, int $weeks = self::WEEKS): array
    {
        $out = [];
        for ($i = $weeks - 1; $i >= 0; $i--) {
            $start = $today->subWeeks($i)->startOfWeek();
            $end = $start->endOfWeek();
            $week = $completions->filter(fn ($c) => $c->created_at->betweenIncluded($start, $end));
            $out[] = ['week' => $start->format('o-\WW'), 'starts_on' => $start->toDateString(), 'tasks' => $week->count(), 'xp' => (int) $week->sum('xp')];
        }

        return $out;
    }

    private static function categories(Collection $completions): array
    {
        return $completions->groupBy(fn ($c) => $c->category ?? 'other')->map->count()->sortDesc()
            ->map(fn ($n, $category) => ['category' => $category, 'tasks' => $n])->values()->all();
    }

    /** Montag = 1 … Sonntag = 7, in der Zeitzone der Person. */
    private static function weekdays(Collection $completions, User $user): array
    {
        $tz = $user->timezone ?: 'Europe/Berlin';
        $counts = $completions->countBy(fn ($c) => $c->created_at->setTimezone($tz)->isoWeekday());

        return collect(range(1, 7))->map(fn ($d) => ['weekday' => $d, 'tasks' => $counts[$d] ?? 0])->all();
    }

    private static function hours(Collection $completions, User $user): array
    {
        $tz = $user->timezone ?: 'Europe/Berlin';
        $counts = $completions->countBy(fn ($c) => (int) $c->created_at->setTimezone($tz)->format('G'));

        return collect(range(0, 23))->map(fn ($h) => ['hour' => $h, 'tasks' => $counts[$h] ?? 0])->all();
    }
}
