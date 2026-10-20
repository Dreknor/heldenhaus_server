<?php

namespace App\Domain\Classes;

use App\Domain\Events\EventEngine;
use App\Models\ActiveBuff;
use App\Models\User;
use App\Models\UserStats;

/**
 * Stellt die Modifikator-Kette einer Person zusammen:
 * eigene Klassen-Passive → Familien-Passive (Schildwall) → Familien-Buffs → Event-Boni → persönliche, scharfe Buffs.
 */
class ModifierChain
{
    /** @return list<Modifier> */
    public static function for(User $user, UserStats $stats): array
    {
        $chain = [];

        match ($stats->class_key) {
            'mage' => $chain[] = Modifiers::focusShield((int) ($user->family?->setting('focus_hour') ?? config('game.focus_hour'))),
            'rogue' => $chain[] = Modifiers::luckyStrike(),
            'tinkerer' => $chain[] = Modifiers::smartConnect((int) config('game.smart_connect_daily_limit')),
            default => null,
        };

        if (self::familyHasClass($user, 'warrior')) {
            $chain[] = Modifiers::shieldWall();
        }

        if ($user->family_id) {
            // Gleiche Familien-Buffs stapeln nicht: je Schlüssel zählt nur einer
            ActiveBuff::current()->where('family_id', $user->family_id)->get()->unique('key')
                ->each(function (ActiveBuff $buff) use (&$chain) {
                    match ($buff->key) {
                        'ancestral_wisdom' => $chain[] = Modifiers::xpBuff($buff),
                        'cheer' => $chain[] = Modifiers::bossBuff($buff),
                        default => null,
                    };
                });
        }

        // Laufende Events der Familie (§12.2); gleichartige Boni stapeln nicht
        foreach (EventEngine::modifiersFor($user) as $m) {
            $chain[] = Modifiers::event($m['event'], $m['modifier']);
        }

        // Elixiere wirken über ihre ganze Laufzeit (nicht scharf, werden nicht verbraucht)
        ActiveBuff::current()->where('user_id', $user->id)->where('armed', false)->whereIn('key', ['elixir_xp', 'elixir_gold', 'elixir_boss'])->get()->unique('key')
            ->each(function (ActiveBuff $buff) use (&$chain) {
                $chain[] = Modifiers::elixir($buff);
            });

        ActiveBuff::current()->where('user_id', $user->id)->where('armed', true)->get()->unique('key')
            ->each(function (ActiveBuff $buff) use (&$chain) {
                $chain[] = Modifiers::armed($buff);
            });

        return $chain;
    }

    public static function applyScore(ScoreContext $context): ScoreContext
    {
        foreach (self::for($context->user, $context->stats) as $modifier) {
            $modifier->onScore($context);
        }

        return $context;
    }

    public static function applyCronDamage(User $user, UserStats $stats, float $damage): float
    {
        foreach (self::for($user, $stats) as $modifier) {
            $damage = $modifier->onCronDamage($damage);
        }

        return $damage;
    }

    /** Hat ein (aktives) Familienmitglied diese Klasse? Familien-Passive wirken einmal, egal wie viele. */
    public static function familyHasClass(User $user, string $classKey): bool
    {
        if (! $user->family_id) {
            return false;
        }

        return UserStats::where('class_key', $classKey)
            ->whereHas('user', fn ($q) => $q->where('family_id', $user->family_id))
            ->exists();
    }
}
