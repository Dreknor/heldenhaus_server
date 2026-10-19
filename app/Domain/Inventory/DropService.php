<?php

namespace App\Domain\Inventory;

use App\Models\GameSetting;
use App\Models\Item;
use App\Models\Task;
use App\Models\TaskCompletion;
use App\Models\UserItem;
use App\Models\UserStats;
use Illuminate\Support\Collection;
use Random\Randomizer;

/**
 * Zufallsfunde beim Erledigen von Aufgaben (Konzept §2.3/§2.5).
 * Chance pro Aufgabe = Grundchance × Schwierigkeitsfaktor (+ Spürsinn), begrenzt durch ein Tageslimit.
 * Welches Item fällt, bestimmen die Drop-Gewichte, die Admins im Backend pflegen.
 */
class DropService
{
    public function __construct(private readonly Randomizer $random = new Randomizer) {}

    public static function settings(): array
    {
        return [
            'base_chance' => (float) GameSetting::get('drop.base_chance', config('game.drops.base_chance')),
            'daily_limit' => (int) GameSetting::get('drop.daily_limit', config('game.drops.daily_limit')),
            'per_bonus_per_point' => (float) GameSetting::get('drop.per_bonus_per_point', config('game.drops.per_bonus_per_point')),
        ];
    }

    /** Wahrscheinlichkeit, dass diese Aufgabe überhaupt etwas abwirft (0–1). */
    public static function chanceFor(float $difficulty, int $perPoints = 0, ?array $settings = null): float
    {
        $s = $settings ?? self::settings();
        $difficultyFactor = 0.5 + min(2.0, $difficulty) / 2; // trivial ≈ 0,55 · leicht 1 · schwer 1,5

        return min(0.95, $s['base_chance'] * $difficultyFactor * (1 + $perPoints * $s['per_bonus_per_point']));
    }

    /** @return Collection<int, Item> mögliche Funde für Kategorie und Level */
    public static function pool(?string $category, int $level): Collection
    {
        return Item::droppable($level)->get()->filter(fn (Item $i) => $i->dropsFor($category))->values();
    }

    public function roll(TaskCompletion $completion, Task $task, UserStats $stats): ?Item
    {
        $settings = self::settings();

        $dropsToday = TaskCompletion::where('user_id', $stats->user_id)
            ->whereNotNull('drop_item_id')
            ->where('created_at', '>=', now()->startOfDay())
            ->count();

        if ($dropsToday >= $settings['daily_limit']) {
            return null;
        }

        if ($this->random->nextFloat() >= self::chanceFor($task->difficulty, $stats->per, $settings)) {
            return null;
        }

        $item = $this->pickWeighted(self::pool($task->category, $stats->level));
        if ($item === null) {
            return null;
        }

        $owned = UserItem::firstOrCreate(['user_id' => $stats->user_id, 'item_id' => $item->id]);
        $owned->increment('quantity');
        $completion->forceFill(['drop_item_id' => $item->id])->save();

        return $item;
    }

    /** Fund zurücknehmen (Rückgängig/Ablehnung). */
    public static function revoke(TaskCompletion $completion): void
    {
        if ($completion->drop_item_id === null) {
            return;
        }

        UserItem::where('user_id', $completion->user_id)
            ->where('item_id', $completion->drop_item_id)
            ->where('quantity', '>', 0)
            ->decrement('quantity');

        $completion->forceFill(['drop_item_id' => null])->save();
    }

    /** @param Collection<int, Item> $items */
    private function pickWeighted(Collection $items): ?Item
    {
        $total = $items->sum('drop_weight');
        if ($total <= 0) {
            return null;
        }

        $roll = $this->random->getInt(1, $total);
        foreach ($items as $item) {
            $roll -= $item->drop_weight;
            if ($roll <= 0) {
                return $item;
            }
        }

        return $items->last();
    }
}
