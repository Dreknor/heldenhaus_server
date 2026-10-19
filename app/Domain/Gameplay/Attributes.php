<?php

namespace App\Domain\Gameplay;

use App\Models\UserEquipment;
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
        foreach ($equipment as $slot) {
            foreach ($slot->item?->stats ?? [] as $key => $bonus) {
                if (isset($result[$key])) {
                    $result[$key] += (int) $bonus;
                }
            }
        }

        return $result;
    }
}
