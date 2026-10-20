<?php

namespace App\Domain\Stats;

use Illuminate\Support\Facades\DB;

/** Einfache Zähler je Person (user_counters) für Erfolge und Statistik. */
class Counters
{
    public static function bump(int $userId, string $key, int $by = 1): void
    {
        DB::table('user_counters')->upsert([['user_id' => $userId, 'key' => $key, 'value' => $by]], ['user_id', 'key'], ['value' => DB::raw('user_counters.value + '.(int) $by)]);
    }

    public static function get(int $userId, string $key): int
    {
        return (int) DB::table('user_counters')->where('user_id', $userId)->where('key', $key)->value('value');
    }
}
