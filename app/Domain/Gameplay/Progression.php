<?php

namespace App\Domain\Gameplay;

use App\Models\Family;
use App\Models\UserStats;

/**
 * Verändert Spielwerte (XP, Level, HP, Gold) und meldet, was dabei passiert ist.
 * Aufrufer sind für Transaktion und Speichern zuständig.
 */
class Progression
{
    /** @return int Anzahl Level-Aufstiege */
    public static function gainXp(UserStats $stats, int $xp): int
    {
        $levelUps = 0;
        $stats->xp += max(0, $xp);

        while ($stats->xp >= Formulas::xpToNextLevel($stats->level)) {
            $stats->xp -= Formulas::xpToNextLevel($stats->level);
            $stats->level++;
            $stats->unspent_points++;
            $stats->hp = Formulas::maxHp($stats);
            $levelUps++;
        }

        return $levelUps;
    }

    /** XP zurücknehmen (Rückgängig/Ablehnung). Kein Level-Verlust – kindgerecht. */
    public static function loseXp(UserStats $stats, int $xp): void
    {
        $stats->xp = max(0, $stats->xp - max(0, $xp));
    }

    public static function changeGold(UserStats $stats, float $amount): void
    {
        $stats->gold = round(max(0, $stats->gold + $amount), 2);
    }

    public static function heal(UserStats $stats, float $amount): void
    {
        $stats->hp = min(Formulas::maxHp($stats), $stats->hp + $amount);
    }

    /**
     * Schaden anwenden. Bei 0 HP gibt es ein „K.O.“: kein Level- oder Ausrüstungsverlust,
     * HP werden wieder aufgefüllt, optional geht ein Anteil Gold verloren (Familien-Einstellung).
     *
     * @return bool true, wenn die Person K.O. gegangen ist
     */
    public static function takeDamage(UserStats $stats, float $damage, ?Family $family = null): bool
    {
        $stats->hp = round($stats->hp - max(0, $damage), 2);

        if ($stats->hp > 0) {
            return false;
        }

        $goldLoss = (float) ($family?->setting('ko_gold_loss') ?? config('game.ko_gold_loss'));
        $stats->gold = round($stats->gold * (1 - $goldLoss), 2);
        $stats->hp = round(Formulas::maxHp($stats) * config('game.ko_revive_hp_ratio'), 2);

        return true;
    }
}
