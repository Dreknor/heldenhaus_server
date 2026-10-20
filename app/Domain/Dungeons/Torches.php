<?php

namespace App\Domain\Dungeons;

use App\Domain\Gameplay\GameException;
use App\Models\User;
use App\Models\UserStats;

/**
 * Fackeln (§2.7): Energie für den Dungeon, nur aus erledigten Aufgaben. Ein Tages- und ein Vorratslimit
 * verhindern, dass man sammelt und dann stundenlang spielt.
 */
class Torches
{
    public static function forDifficulty(float $difficulty): int
    {
        $t = config('game.dungeon.torches');

        return match (true) {
            $difficulty <= 0.1 => $t['trivial'],
            $difficulty <= 1.0 => $t['easy'],
            $difficulty <= 1.5 => $t['medium'],
            default => $t['hard'],
        };
    }

    /** Gibt die tatsächlich gutgeschriebenen Fackeln zurück. */
    public static function award(User $user, int $amount): int
    {
        $stats = UserStats::lockForUpdate()->find($user->id);
        if ($stats === null || $amount <= 0) {
            return 0;
        }

        $today = $user->gameDate()->toDateString();
        if ($stats->torches_on?->toDateString() !== $today) {
            $stats->torches_today = 0;
            $stats->torches_on = $today;
        }

        $add = max(0, min($amount, config('game.dungeon.torch_daily_max') - $stats->torches_today, config('game.dungeon.torch_store_max') - $stats->torches));
        $stats->torches += $add;
        $stats->torches_today += $add;
        $stats->save();

        return $add;
    }

    public static function spend(UserStats $stats, int $amount): void
    {
        if ($amount <= 0) {
            return;
        }
        if ($stats->torches < $amount) {
            throw new GameException('Deine Fackeln sind aus. Erledige eine Aufgabe, dann geht es weiter!');
        }
        $stats->torches -= $amount;
    }
}
