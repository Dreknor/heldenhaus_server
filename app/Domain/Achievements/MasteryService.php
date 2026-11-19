<?php

namespace App\Domain\Achievements;

use App\Domain\Badges\BadgeService;
use App\Enums\CompletionStatus;
use App\Enums\TaskType;
use App\Models\MasteryLevel;
use App\Models\TaskCompletion;
use App\Models\User;

/**
 * Meisterschaften je Aufgabenkategorie (§14.7): Stufen I–V nach Anzahl erledigter Aufgaben (25/100/250/500/1000).
 * Gezählt wird wie bei den Erfolgen: positive Abschlüsse ohne offene, abgelehnte oder zurückgenommene.
 * Eine erreichte Stufe bleibt; jede Stufe gibt einmal Gold und ein Abzeichen.
 */
class MasteryService
{
    public function __construct(private readonly BadgeService $badges) {}

    /** Gezählte Abschlüsse je Kategorie. @return array<string, int> */
    public static function counts(User $user, ?string $category = null): array
    {
        $categories = array_keys(LongTermCatalog::masteries()['categories']);

        $rows = TaskCompletion::query()
            ->join('tasks', 'tasks.id', '=', 'task_completions.task_id')
            ->where('task_completions.user_id', $user->id)
            ->where('task_completions.direction', 'up')
            ->whereIn('task_completions.status', [CompletionStatus::None, CompletionStatus::Approved, CompletionStatus::Auto])
            ->where('tasks.type', '!=', TaskType::Reward)
            ->whereIn('tasks.category', $category ? [$category] : $categories)
            ->groupBy('tasks.category')
            ->selectRaw('tasks.category as cat, count(*) as n')
            ->pluck('n', 'cat');

        return collect($category ? [$category] : $categories)->mapWithKeys(fn ($c) => [$c => (int) ($rows[$c] ?? 0)])->all();
    }

    public static function levelFor(int $count): int
    {
        return collect(LongTermCatalog::masteries()['thresholds'])->filter(fn (int $t) => $count >= $t)->count();
    }

    /** „Meisterschaft Haushalt III“ – neutral, ohne Geschlechtsform. */
    public static function title(string $category, int $level): ?string
    {
        $m = LongTermCatalog::masteries();
        $name = $m['categories'][$category]['name'] ?? null;

        return $level > 0 && $name ? "Meisterschaft {$name} {$m['numerals'][$level - 1]}" : null;
    }

    /**
     * Stände neu zählen (eine oder alle Kategorien), neue Stufen festhalten und belohnen.
     *
     * @return list<array{category: string, level: int, title: string}> neu erreichte Stufen
     */
    public function refresh(User $user, ?string $category = null): array
    {
        $m = LongTermCatalog::masteries();
        if ($category !== null && ! isset($m['categories'][$category])) {
            return [];
        }

        $reached = [];
        foreach (self::counts($user, $category) as $cat => $count) {
            $row = MasteryLevel::firstOrNew(['user_id' => $user->id, 'category' => $cat]);
            $level = max($row->level, self::levelFor($count));
            $before = (int) $row->level;
            $row->fill(['count' => $count, 'level' => $level]);
            if ($row->isDirty() || ! $row->exists) {
                $row->save();
            }

            for ($l = $before + 1; $l <= $level; $l++) {
                $title = self::title($cat, $l);
                $badge = $this->badges->award($user, "mastery:{$cat}:{$l}", 'category', $title, $m['categories'][$cat]['icon'],
                    ['category' => $cat, 'level' => $l], "{$user->name} hat die {$title} erreicht!");
                if ($badge !== null) {
                    LongTermRewards::grant($user, ['gold' => $m['gold'][$l - 1] ?? 0]);
                    $reached[] = ['category' => $cat, 'level' => $l, 'title' => $title];
                }
            }
        }

        return $reached;
    }

    /** @return list<array<string, mixed>> */
    public static function present(User $user): array
    {
        $m = LongTermCatalog::masteries();
        $rows = MasteryLevel::where('user_id', $user->id)->get()->keyBy('category');
        $thresholds = $m['thresholds'];

        return collect($m['categories'])->map(function (array $c, string $key) use ($rows, $thresholds, $m) {
            $row = $rows->get($key);
            $level = (int) ($row?->level ?? 0);
            $count = (int) ($row?->count ?? 0);
            $next = $thresholds[$level] ?? null;
            $prev = $level > 0 ? $thresholds[$level - 1] : 0;

            return [
                'category' => $key,
                'name' => $c['name'],
                'label' => config("game.categories.{$key}", $c['name']),
                'icon' => $c['icon'],
                'count' => $count,
                'level' => $level,
                'numeral' => $level > 0 ? $m['numerals'][$level - 1] : null,
                'title' => self::title($key, $level),
                'next' => $next,
                'progress' => $next ? round(($count - $prev) / max(1, $next - $prev), 3) : 1.0,
            ];
        })->values()->all();
    }

    /** Höchste Meisterschaft als Titel unter dem Namen (bei Gleichstand die mit mehr Aufgaben). */
    public static function bestTitle(User $user): ?string
    {
        $best = MasteryLevel::where('user_id', $user->id)->where('level', '>', 0)
            ->orderByDesc('level')->orderByDesc('count')->first();

        return $best ? self::title($best->category, $best->level) : null;
    }
}
