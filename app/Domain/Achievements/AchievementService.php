<?php

namespace App\Domain\Achievements;

use App\Domain\Social\FeedService;
use App\Domain\Stats\Counters;
use App\Enums\CompletionStatus;
use App\Enums\FamilyRole;
use App\Enums\TaskType;
use App\Models\CraftJob;
use App\Models\DayLog;
use App\Models\DungeonPosition;
use App\Models\EventParticipation;
use App\Models\FeedReaction;
use App\Models\FocusSession;
use App\Models\GuestbookEntry;
use App\Models\Quest;
use App\Models\TaskAssignment;
use App\Models\TaskCompletion;
use App\Models\User;
use App\Models\UserAchievement;
use App\Models\UserEquipment;
use App\Models\UserPet;
use App\Models\UserStats;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Erfolge/Abzeichen (Konzept §2.1): Stufen-Erfolge, die aus vorhandenen Spieldaten berechnet werden.
 * Einmal erreichte Stufen bleiben – auch wenn ein Abschluss später zurückgenommen wird (kindgerecht,
 * wie beim Level). Wer etwas zurücknimmt und erneut abhakt, bekommt dieselbe Stufe nicht zweimal.
 */
class AchievementService
{
    public function __construct(private readonly FeedService $feed) {}

    /** @return array<string, array> */
    public static function catalog(): array
    {
        static $catalog = null;

        return $catalog ??= collect(json_decode(file_get_contents(database_path('content/achievements.json')), true, flags: JSON_THROW_ON_ERROR))
            ->keyBy('key')
            ->all();
    }

    /**
     * Neue Stufen vergeben. Mit $metrics werden nur Erfolge geprüft, die von diesen Kennzahlen abhängen.
     *
     * @param  list<string>|null  $metrics
     * @return Collection<int, UserAchievement> neu erreichte Stufen
     */
    public function evaluate(User $user, ?array $metrics = null, bool $announce = true): Collection
    {
        $achievements = collect(self::catalog())
            ->filter(fn (array $a) => $metrics === null || in_array($a['metric'], $metrics, true));

        if ($achievements->isEmpty()) {
            return collect();
        }

        $reached = UserAchievement::where('user_id', $user->id)
            ->get(['achievement_key', 'tier'])
            ->groupBy('achievement_key')
            ->map(fn (Collection $rows) => $rows->max('tier'));

        $values = [];
        $unlocked = collect();

        foreach ($achievements as $key => $achievement) {
            $tiers = $achievement['tiers'];
            if (($reached[$key] ?? 0) >= count($tiers)) {
                continue;
            }

            $value = $values[$achievement['metric']] ??= $this->metric($user, $achievement['metric']);

            foreach ($tiers as $index => $threshold) {
                $tier = $index + 1;
                if ($tier <= ($reached[$key] ?? 0) || $value < $threshold) {
                    continue;
                }

                $gold = (int) ($achievement['gold'][$index] ?? 0);
                $inserted = UserAchievement::query()->insertOrIgnore([
                    'user_id' => $user->id,
                    'achievement_key' => $key,
                    'tier' => $tier,
                    'threshold' => $threshold,
                    'reward_gold' => $gold,
                    'seen_at' => $announce ? null : now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if ($inserted) {
                    $unlocked->push(UserAchievement::where(['user_id' => $user->id, 'achievement_key' => $key, 'tier' => $tier])->first());
                }
            }
        }

        if ($unlocked->isNotEmpty()) {
            $gold = $unlocked->sum('reward_gold');
            if ($gold > 0) {
                UserStats::whereKey($user->id)->increment('gold', $gold);
            }

            if ($announce) {
                // Je Erfolg nur die höchste neue Stufe melden, damit der Feed nicht überläuft
                $unlocked->groupBy('achievement_key')->each(function (Collection $rows, string $key) use ($user) {
                    $best = $rows->sortByDesc('tier')->first();
                    $this->feed->post($user->family_id, $user, 'achievement', '🏅', "{$user->name} hat den Erfolg „".self::title($key, $best->tier).'“ erreicht!', [
                        'achievement' => $key,
                        'tier' => $best->tier,
                    ]);
                });
            }
        }

        return $unlocked;
    }

    /** Die Feier für alle neuen Erfolge wurde gezeigt. */
    public function markSeen(User $user): int
    {
        return UserAchievement::where('user_id', $user->id)->whereNull('seen_at')->update(['seen_at' => now()]);
    }

    public function metric(User $user, string $metric): int
    {
        $done = fn (): Builder => TaskCompletion::query()
            ->where('task_completions.user_id', $user->id)
            ->where('direction', 'up')
            ->whereIn('task_completions.status', [CompletionStatus::None, CompletionStatus::Approved, CompletionStatus::Auto])
            ->join('tasks', 'tasks.id', '=', 'task_completions.task_id');

        $confirmed = fn (): Builder => $done()->whereIn('task_completions.status', [CompletionStatus::Approved, CompletionStatus::Auto]);

        return (int) match ($metric) {
            'tasks_done' => $done()->whereIn('tasks.type', [TaskType::Daily, TaskType::Todo])->count(),
            'habits_up' => $done()->where('tasks.type', TaskType::Habit)->count(),
            'best_streak' => TaskAssignment::where('user_id', $user->id)->max('streak') ?? 0,
            'perfect_days' => DayLog::where('user_id', $user->id)->where('dailies_due', '>', 0)->whereColumn('dailies_done', '>=', 'dailies_due')->count(),
            'family_tasks' => $confirmed()->where('tasks.scope', 'family')->count(),
            'team_tasks' => $confirmed()->where('tasks.assignment_mode', 'team')->count(),
            'stars_earned' => $confirmed()->sum('task_completions.stars'),
            'reactions_given' => FeedReaction::where('user_id', $user->id)->count(),
            'bosses_defeated' => $this->wonQuests($user)->where('type', 'boss')->count(),
            'collect_quests' => $this->wonQuests($user)->where('type', 'collect')->count(),
            'pets' => UserPet::where('user_id', $user->id)->count(),
            'mounts' => UserPet::where('user_id', $user->id)->where('is_mount', true)->count(),
            'level' => $user->stats()->value('level') ?? 1,
            'class_chosen' => $user->stats()->whereNotNull('class_key')->exists() ? 1 : 0,
            'equipped_slots' => UserEquipment::where('user_id', $user->id)->count(),
            'items_crafted' => CraftJob::where('user_id', $user->id)->where('status', 'collected')->sum('quantity'),
            'dungeons_cleared' => DungeonPosition::where('user_id', $user->id)->whereHas('dungeon', fn (Builder $q) => $q->where('status', 'completed'))->count(),
            'monsters_defeated' => Counters::get($user->id, 'monsters_defeated'),
            'harvests' => Counters::get($user->id, 'harvests'),
            'focus_sessions' => FocusSession::where('user_id', $user->id)->where('result', 'done')->count(),
            // Beendete Events, bei denen man mit Event-Punkten dabei war
            'events_joined' => EventParticipation::where('user_id', $user->id)->where('points', '>', 0)
                ->whereHas('familyEvent', fn ($q) => $q->where('status', 'finished'))->count(),
            'guestbook' => GuestbookEntry::where('user_id', $user->id)->count(),
            default => 0,
        };
    }

    /** Geschaffte Quests: eigene Solo-Quests und gemeinsame Familien-Quests (Gäste ausgenommen, wie bei der Belohnung). */
    private function wonQuests(User $user): Builder
    {
        return Quest::where('status', 'completed')->where(function (Builder $q) use ($user) {
            $q->where(fn (Builder $q) => $q->where('scope', 'solo')->where('user_id', $user->id));

            if ($user->family_id && $user->family_role !== FamilyRole::Guest) {
                $q->orWhere(fn (Builder $q) => $q->where('scope', 'family')->where('family_id', $user->family_id));
            }
        });
    }

    public static function title(string $key, int $tier): string
    {
        $achievement = self::catalog()[$key] ?? null;
        if ($achievement === null) {
            return $key;
        }

        return count($achievement['tiers']) > 1 ? "{$achievement['name']} ".self::roman($tier) : $achievement['name'];
    }

    public static function describe(array $achievement, int $threshold): string
    {
        if ($threshold === 1 && isset($achievement['description_one'])) {
            return $achievement['description_one'];
        }

        return str_replace('{n}', number_format($threshold, 0, ',', '.'), $achievement['description']);
    }

    private static function roman(int $n): string
    {
        return ['', 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X'][$n] ?? (string) $n;
    }

    // ---------- Darstellung (Web + API) ----------

    /** Alle Erfolge mit Fortschritt, gruppiert wie im Katalog. */
    public function present(User $user): array
    {
        $unlocked = UserAchievement::where('user_id', $user->id)->get()->groupBy('achievement_key');
        $values = [];

        return collect(self::catalog())->map(function (array $a, string $key) use ($user, $unlocked, &$values) {
            $rows = $unlocked->get($key, collect())->sortBy('tier');
            $tier = (int) ($rows->max('tier') ?? 0);
            $value = $values[$a['metric']] ??= $this->metric($user, $a['metric']);
            $next = $a['tiers'][$tier] ?? null;
            $previous = $tier > 0 ? $a['tiers'][$tier - 1] : 0;

            return [
                'key' => $key,
                'group' => $a['group'],
                'name' => $a['name'],
                'title' => $tier > 0 ? self::title($key, $tier) : $a['name'],
                'motif' => $a['motif'],
                'tier' => $tier,
                'tiers' => count($a['tiers']),
                'value' => $value,
                'next' => $next,
                'next_description' => $next !== null ? self::describe($a, $next) : null,
                'progress' => $next !== null ? round(min(1, max(0, ($value - $previous) / max(1, $next - $previous))), 3) : 1,
                'unlocked' => $rows->map(fn (UserAchievement $r) => [
                    'tier' => $r->tier,
                    'title' => self::title($key, $r->tier),
                    'description' => self::describe($a, $r->threshold),
                    'gold' => $r->reward_gold,
                    'at' => $r->created_at->toIso8601String(),
                ])->values(),
            ];
        })->values()->all();
    }

    /** Neu erreichte Stufen, deren Feier noch aussteht. */
    public static function unseen(User $user): array
    {
        return UserAchievement::where('user_id', $user->id)->whereNull('seen_at')->orderBy('id')->get()
            ->map(function (UserAchievement $r) {
                $a = self::catalog()[$r->achievement_key] ?? null;

                return $a === null ? null : [
                    'id' => $r->id,
                    'key' => $r->achievement_key,
                    'title' => self::title($r->achievement_key, $r->tier),
                    'description' => self::describe($a, $r->threshold),
                    'motif' => $a['motif'],
                    'tier' => $r->tier,
                    'tiers' => count($a['tiers']),
                    'gold' => $r->reward_gold,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }
}
