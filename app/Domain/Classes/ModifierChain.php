<?php

namespace App\Domain\Classes;

use App\Domain\Events\EventEngine;
use App\Models\ActiveBuff;
use App\Models\User;
use App\Models\UserStats;

/**
 * Stellt die Modifikator-Kette einer Person zusammen:
 * eigene Klassen-Passive → Familien-Passive (Schildwall) → Familien-Buffs (inkl. Schutzsegen) → Event-Boni → persönliche, scharfe Buffs.
 */
class ModifierChain
{
    /** Kategorien des Waldläufers (Fährtenleser, Wildnisruf) */
    public const WILD_CATEGORIES = ['sport', 'nature'];

    /** @return list<Modifier> */
    public static function for(User $user, UserStats $stats): array
    {
        $chain = [];

        match ($stats->class_key) {
            'mage' => $chain[] = Modifiers::focusShield((int) ($user->family?->setting('focus_hour') ?? config('game.focus_hour'))),
            'rogue' => $chain[] = Modifiers::luckyStrike(),
            'tinkerer' => $chain[] = Modifiers::smartConnect((int) config('game.smart_connect_daily_limit')),
            'ranger' => $chain[] = Modifiers::categoryBonus('tracker', self::WILD_CATEGORIES, 0.2),
            'cook' => $chain[] = Modifiers::categoryBonus('home_cooking', ['household'], 0.2),
            'paladin' => $chain[] = Modifiers::oath((int) config('game.oath_min_streak')),
            'monk' => $chain[] = Modifiers::innerCalm((int) config('game.inner_calm_daily_limit')),
            default => null,
        };

        // Passive Fähigkeiten-Stufen ab Level 15/20/25 (§14.7), Wirkung aus classes.json
        foreach (ClassCatalog::tiers($stats->class_key, $stats->level) as $tier) {
            if ($tier['kind'] === 'passive') {
                $chain[] = Modifiers::skillEffect($tier['key'], $tier['effect']);
            }
        }

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
                        'wild_call' => $chain[] = Modifiers::categoryBonus('wild_call', self::WILD_CATEGORIES, $buff->value),
                        'blessing' => $chain[] = Modifiers::blessing($buff),
                        default => self::tierBuff($chain, $buff),
                    };
                });
        }

        // Persönliche Buffs aus Fähigkeiten-Stufen (Schutzschild von anderen, eigene Buffs ohne Familie)
        ActiveBuff::current()->where('user_id', $user->id)->where('armed', false)->get()->unique('key')
            ->each(function (ActiveBuff $buff) use (&$chain) {
                self::tierBuff($chain, $buff);
            });

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
                $effect = ClassCatalog::skill($buff->key)['effect'] ?? null;
                $chain[] = $effect !== null ? Modifiers::skillEffect($buff->key, $effect, $buff) : Modifiers::armed($buff);
            });

        return $chain;
    }

    /** Buff einer Fähigkeiten-Stufe anhängen, falls der Schlüssel eine ist (sonst nichts). */
    private static function tierBuff(array &$chain, ActiveBuff $buff): void
    {
        $effect = ClassCatalog::skill($buff->key)['effect'] ?? null;
        if ($effect !== null) {
            $chain[] = Modifiers::skillEffect($buff->key, $effect, $buff);
        }
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
