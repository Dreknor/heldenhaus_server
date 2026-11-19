<?php

namespace App\Domain\Achievements;

use App\Domain\Inventory\Stock;
use App\Models\Item;
use App\Models\User;
use App\Models\UserStats;

/** Belohnungen der Langzeitziele auszahlen: Gold und ein Gegenstand (Möbel, Kosmetik, Schlüpfpulver). */
class LongTermRewards
{
    /**
     * Gold gutschreiben und den Gegenstand ins Inventar legen (Belohnungen kommen immer an, auch bei vollem Inventar).
     * Fehlt der Gegenstand im Katalog (z. B. vom Admin entfernt), gibt es nur das Gold.
     *
     * @param  array{gold?: int|float, item?: string}  $reward
     * @return array{gold: float, item: ?array{key: string, name: string, icon: string, type: string}}
     */
    public static function grant(User $user, array $reward): array
    {
        $gold = (float) ($reward['gold'] ?? 0);
        if ($gold > 0) {
            UserStats::whereKey($user->id)->increment('gold', $gold);
        }

        $item = isset($reward['item']) ? Item::firstWhere('key', $reward['item']) : null;
        if ($item !== null) {
            Stock::add($user->id, $item->id, 1);
        }

        return ['gold' => $gold, 'item' => $item?->only(['key', 'name', 'icon', 'type'])];
    }

    /** Vorschau ohne Auszahlung (für die Anzeige der nächsten Belohnung). */
    public static function preview(array $reward): array
    {
        $item = isset($reward['item']) ? Item::firstWhere('key', $reward['item']) : null;

        return ['gold' => (float) ($reward['gold'] ?? 0), 'item' => $item?->only(['key', 'name', 'icon', 'type']), 'badge' => (bool) ($reward['badge'] ?? false)];
    }
}
