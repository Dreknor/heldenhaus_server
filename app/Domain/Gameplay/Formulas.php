<?php

namespace App\Domain\Gameplay;

use App\Domain\Classes\ClassCatalog;
use App\Enums\AgeGroup;
use App\Models\Family;
use App\Models\UserStats;

/**
 * Reine Spielformeln (ohne Datenbank), siehe docs/konzept/02-spielmechanik.md §2.2.
 */
class Formulas
{
    public static function clampValue(float $value): float
    {
        return max(config('game.value_min'), min(config('game.value_max'), $value));
    }

    /** delta = 0.9747 ^ value – vernachlässigte (rote) Aufgaben bringen mehr. */
    public static function delta(float $value): float
    {
        return config('game.delta_base') ** self::clampValue($value);
    }

    /** XP, die für den Aufstieg von $level auf $level+1 nötig sind. */
    public static function xpToNextLevel(int $level): int
    {
        return (int) (round(($level ** 2 * 0.25 + 10 * $level + 139.75) / 10) * 10);
    }

    public static function xp(float $delta, float $difficulty, int $intPoints = 0): int
    {
        $bonus = 1 + $intPoints * config('game.int_bonus_per_point');

        return (int) round($delta * $difficulty * config('game.xp_factor') * $bonus);
    }

    public static function streakBonus(int $streak): float
    {
        return min(config('game.streak_bonus_cap'), 1 + $streak / 100);
    }

    public static function gold(float $delta, float $difficulty, int $perPoints = 0, int $streak = 0): float
    {
        $bonus = 1 + $perPoints * config('game.per_bonus_per_point');

        return round($delta * $difficulty * $bonus * self::streakBonus($streak), 2);
    }

    public static function ageDamageFactor(AgeGroup $group, ?Family $family = null): float
    {
        $default = config("game.age_damage_factor.{$group->value}", 1.0);

        return (float) ($family?->setting("damage_factor.{$group->value}") ?? $default);
    }

    /** Schaden durch verpasste Daily bzw. negative Gewohnheit (positiver Wert = HP-Verlust). */
    public static function damage(float $delta, float $difficulty, int $conPoints, float $ageFactor): float
    {
        $conReduction = min(config('game.con_bonus_max'), $conPoints * config('game.con_bonus_per_point'));

        return round($delta * $difficulty * config('game.damage_factor') * (1 - $conReduction) * $ageFactor, 2);
    }

    /** Krieger haben 60 statt 50 HP (Klassenwerte aus classes.json). */
    public static function maxHp(UserStats $stats): float
    {
        return (float) (ClassCatalog::get($stats->class_key)['max_hp'] ?? config('game.max_hp'));
    }

    /** Magier haben 50 % mehr Mana. */
    public static function maxMp(UserStats $stats): float
    {
        $multiplier = (float) (ClassCatalog::get($stats->class_key)['mp_multiplier'] ?? 1.0);

        return round((config('game.max_mp_base') + $stats->int * 2 + ($stats->level - 1)) * $multiplier, 1);
    }

    /** Grundschaden einer Aufgabe am Familien-Boss. */
    public static function bossDamage(float $delta, float $difficulty, int $strPoints = 0): float
    {
        return round($delta * $difficulty * config('game.boss_damage_factor') * (1 + $strPoints * config('game.str_bonus_per_point')), 2);
    }
}
