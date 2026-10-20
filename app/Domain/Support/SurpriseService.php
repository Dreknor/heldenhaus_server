<?php

namespace App\Domain\Support;

use App\Domain\Gameplay\Progression;
use App\Models\Item;
use App\Models\Task;
use App\Models\TaskCompletion;
use App\Models\UserItem;
use App\Models\UserStats;
use Random\Randomizer;

/**
 * Überraschungen bei Routineaufgaben (§11.3): kleine Chance auf Bonus-Gold, Futter oder ein Material.
 * Pech-Schutz: Die Chance steigt mit jeder Erledigung ohne Überraschung. Tageslimit wie bei Drops.
 * Nie Sterne, nie Echtgeld.
 */
class SurpriseService
{
    public function __construct(private readonly Randomizer $random = new Randomizer) {}

    public static function chance(int $pity): float
    {
        $c = config('game.surprise');

        return min($c['max_chance'], $c['base_chance'] + $pity * $c['pity_step']);
    }

    public function roll(TaskCompletion $completion, Task $task, UserStats $stats): ?array
    {
        if ($task->status === 'inbox') {
            return null;
        }

        $today = now()->toDateString();
        if ($stats->surprises_on?->toDateString() !== $today) {
            $stats->surprises_today = 0;
            $stats->surprises_on = $today;
        }

        if ($stats->surprises_today >= config('game.surprise.daily_limit')) {
            $stats->save();

            return null;
        }

        if ($this->random->nextFloat() >= self::chance($stats->surprise_pity)) {
            $stats->surprise_pity++;
            $stats->save();

            return null;
        }

        $stats->surprise_pity = 0;
        $stats->surprises_today++;

        $item = $this->random->nextFloat() < 0.5 ? $this->pickItem($stats->level) : null;
        if ($item) {
            UserItem::firstOrCreate(['user_id' => $stats->user_id, 'item_id' => $item->id])->increment('quantity');
            $surprise = ['type' => 'item', 'item_id' => $item->id, 'key' => $item->key, 'name' => $item->name, 'icon' => $item->icon];
        } else {
            [$min, $max] = config('game.surprise.gold');
            $gold = $this->random->getInt($min, $max);
            Progression::changeGold($stats, $gold);
            $completion->gold = round($completion->gold + $gold, 2); // beim Rückgängigmachen mit zurück
            $surprise = ['type' => 'gold', 'gold' => $gold, 'icon' => '🪙'];
        }

        $stats->save();
        $completion->forceFill(['surprise' => $surprise])->save();

        return $surprise;
    }

    public static function revoke(TaskCompletion $completion): void
    {
        if (($completion->surprise['type'] ?? null) === 'item') {
            UserItem::where('user_id', $completion->user_id)->where('item_id', $completion->surprise['item_id'])
                ->where('quantity', '>', 0)->decrement('quantity');
        }
    }

    private function pickItem(int $level): ?Item
    {
        $items = Item::where('active', true)->whereIn('type', ['food', 'material'])->where('min_level', '<=', $level)
            ->whereIn('rarity', ['common', 'uncommon'])->get();

        return $items->isEmpty() ? null : $items[$this->random->getInt(0, $items->count() - 1)];
    }
}
