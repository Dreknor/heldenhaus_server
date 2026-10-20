<?php

namespace App\Domain\Gameplay;

use App\Models\UserEquipment;
use App\Models\UserItem;
use App\Models\UserStats;

/** Wirksame Attribute = verteilte Punkte + Boni der angelegten Ausrüstung. */
class Attributes
{
    public const KEYS = ['str', 'int', 'con', 'per'];

    /** @return array{str: int, int: int, con: int, per: int} */
    public static function for(UserStats $stats): array
    {
        $result = [];
        foreach (self::KEYS as $key) {
            $result[$key] = (int) $stats->{$key};
        }

        $equipment = UserEquipment::with('item')->where('user_id', $stats->user_id)->get();
        // Meisterhaft hergestellte Stücke (§2.5) geben +1 auf jeden ihrer Werte
        $master = UserItem::where('user_id', $stats->user_id)->whereIn('item_id', $equipment->pluck('item_id'))->where('master_quantity', '>', 0)->pluck('item_id')->all();
        foreach ($equipment as $slot) {
            foreach ($slot->item?->stats ?? [] as $key => $bonus) {
                if (isset($result[$key])) {
                    $result[$key] += (int) $bonus + (in_array($slot->item_id, $master, true) && $bonus > 0 ? 1 : 0);
                }
            }
        }

        return $result;
    }
}
