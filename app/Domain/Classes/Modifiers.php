<?php

namespace App\Domain\Classes;

use App\Enums\TaskType;
use App\Models\ActiveBuff;
use App\Models\TaskCompletion;

/**
 * Alle Klassen- und Buff-Effekte als kleine Modifikatoren.
 * Die Kette selbst baut ModifierChain zusammen.
 */
final class Modifiers
{
    /** Krieger, passiv: −25 % Schaden beim Tageswechsel für die ganze Familie (stapelt nicht). */
    public static function shieldWall(): Modifier
    {
        return new class extends Modifier
        {
            public function onCronDamage(float $damage): float
            {
                return round($damage * 0.75, 2);
            }
        };
    }

    /** Magier, passiv: +2 Mana für Dailies, die vor der Fokus-Uhrzeit erledigt werden. */
    public static function focusShield(int $focusHour): Modifier
    {
        return new class($focusHour) extends Modifier
        {
            public function __construct(private readonly int $focusHour) {}

            public function onScore(ScoreContext $c): void
            {
                $localHour = (int) now($c->user->timezone)->format('G');

                if ($c->task->type === TaskType::Daily && $localHour < $this->focusHour) {
                    $c->mpGain += 2;
                    $c->effects[] = 'focus_shield';
                }
            }
        };
    }

    /** Schurke, passiv: 15 % Chance auf dreifaches Gold. */
    public static function luckyStrike(): Modifier
    {
        return new class extends Modifier
        {
            public function onScore(ScoreContext $c): void
            {
                if ($c->random->nextFloat() < 0.15) {
                    $c->goldMultiplier *= 3;
                    $c->effects[] = 'lucky_strike';
                }
            }
        };
    }

    /** Tüftler, passiv: +25 % XP und Gold für Abschlüsse über Home Assistant (Tageslimit). */
    public static function smartConnect(int $dailyLimit): Modifier
    {
        return new class($dailyLimit) extends Modifier
        {
            public function __construct(private readonly int $dailyLimit) {}

            public function onScore(ScoreContext $c): void
            {
                if (! $c->viaHomeAssistant()) {
                    return;
                }

                $today = TaskCompletion::where('user_id', $c->user->id)
                    ->where('source', 'ha')
                    ->where('created_at', '>=', now()->startOfDay())
                    ->whereJsonContains('effects', 'smart_connect')
                    ->count();

                if ($today < $this->dailyLimit) {
                    $c->xpMultiplier *= 1.25;
                    $c->goldMultiplier *= 1.25;
                    $c->effects[] = 'smart_connect';
                }
            }
        };
    }

    /**
     * Waldläufer „Fährtenleser“ und Koch „Hausmannskost“: +X % XP und Gold für Aufgaben bestimmter Kategorien.
     * Mit dem Familien-Buff „Wildnisruf“ wirkt dieselbe Art Bonus auf alle.
     *
     * @param  list<string>  $categories
     */
    public static function categoryBonus(string $key, array $categories, float $bonus): Modifier
    {
        return new class($key, $categories, $bonus) extends Modifier
        {
            public function __construct(private readonly string $key, private readonly array $categories, private readonly float $bonus) {}

            public function onScore(ScoreContext $c): void
            {
                if (in_array($c->task->category, $this->categories, true)) {
                    $c->xpMultiplier *= 1 + $this->bonus;
                    $c->goldMultiplier *= 1 + $this->bonus;
                    $c->effects[] = $this->key;
                }
            }
        };
    }

    /** Paladin, passiv „Treueschwur“: +15 % XP für Dailies mit einer Serie ab 7 Tagen. */
    public static function oath(int $minStreak): Modifier
    {
        return new class($minStreak) extends Modifier
        {
            public function __construct(private readonly int $minStreak) {}

            public function onScore(ScoreContext $c): void
            {
                if ($c->task->type === TaskType::Daily && $c->streak >= $this->minStreak) {
                    $c->xpMultiplier *= 1.15;
                    $c->effects[] = 'oath';
                }
            }
        };
    }

    /** Mönch, passiv „Innere Ruhe“: +1 Mana je positiver Gewohnheit (Tageslimit). */
    public static function innerCalm(int $dailyLimit): Modifier
    {
        return new class($dailyLimit) extends Modifier
        {
            public function __construct(private readonly int $dailyLimit) {}

            public function onScore(ScoreContext $c): void
            {
                if ($c->task->type !== TaskType::Habit) {
                    return;
                }

                $today = TaskCompletion::where('user_id', $c->user->id)
                    ->where('created_at', '>=', now()->startOfDay())
                    ->whereJsonContains('effects', 'inner_calm')
                    ->count();

                if ($today < $this->dailyLimit) {
                    $c->mpGain += 1;
                    $c->effects[] = 'inner_calm';
                }
            }
        };
    }

    /** Familien-Buff (Paladin „Schutzsegen“): weniger Schaden beim Tageswechsel. */
    public static function blessing(ActiveBuff $buff): Modifier
    {
        return new class($buff) extends Modifier
        {
            public function __construct(private readonly ActiveBuff $buff) {}

            public function onCronDamage(float $damage): float
            {
                return round($damage * (1 - $this->buff->value), 2);
            }
        };
    }

    /** Familien-Buff (Magier „Weisheit der Ahnen“): +X % XP. */
    public static function xpBuff(ActiveBuff $buff): Modifier
    {
        return new class($buff) extends Modifier
        {
            public function __construct(private readonly ActiveBuff $buff) {}

            public function onScore(ScoreContext $c): void
            {
                $c->xpMultiplier *= 1 + $this->buff->value;
                $c->effects[] = $this->buff->key;
            }
        };
    }

    /** Elixier-Buffs (Tränke mit Laufzeit): +X % EP, Gold oder Boss-Schaden. */
    public static function elixir(ActiveBuff $buff): Modifier
    {
        return new class($buff) extends Modifier
        {
            public function __construct(private readonly ActiveBuff $buff) {}

            public function onScore(ScoreContext $c): void
            {
                match ($this->buff->key) {
                    'elixir_xp' => $c->xpMultiplier *= 1 + $this->buff->value,
                    'elixir_gold' => $c->goldMultiplier *= 1 + $this->buff->value,
                    'elixir_boss' => $c->bossMultiplier *= 1 + $this->buff->value,
                    default => null,
                };
                $c->effects[] = $this->buff->key;
            }
        };
    }

    /**
     * Event-Boni (§12.2): category_bonus (+X % XP/Gold in einer Kategorie), all_bonus (für alle Aufgaben),
     * parent_bonus (Rollentausch: Bonus-XP für Eltern). Andere Event-Effekte wirken an ihrer eigenen Stelle.
     */
    public static function event(string $eventKey, array $modifier): Modifier
    {
        return new class($eventKey, $modifier) extends Modifier
        {
            public function __construct(private readonly string $eventKey, private readonly array $m) {}

            public function onScore(ScoreContext $c): void
            {
                $applies = match ($this->m['type']) {
                    'category_bonus' => $c->task->category === ($this->m['category'] ?? null),
                    'all_bonus' => true,
                    'parent_bonus' => $c->user->isParent(),
                    default => false,
                };
                if (! $applies) {
                    return;
                }

                $c->xpMultiplier *= 1 + (float) ($this->m['xp'] ?? 0);
                $c->goldMultiplier *= 1 + (float) ($this->m['gold'] ?? 0);
                $c->effects[] = "event:{$this->eventKey}";
            }
        };
    }

    /**
     * Fähigkeiten-Stufen ab Level 15/20/25 (§14.7) – ein Modifikator für alle Effekt-Typen aus classes.json:
     * score_bonus (passiv), family_buff (Familien-Buff), armed_next (nächster passender Abschluss, wird verbraucht),
     * cron_shield (passiv, eigener Schaden) und member_shield (Schutz für ein Familienmitglied).
     * Bedingungen in „when“: categories, task_types, min_difficulty, pool, min_streak.
     */
    public static function skillEffect(string $key, array $effect, ?ActiveBuff $buff = null): Modifier
    {
        return new class($key, $effect, $buff) extends Modifier
        {
            public function __construct(private readonly string $key, private readonly array $e, private readonly ?ActiveBuff $buff) {}

            public function onScore(ScoreContext $c): void
            {
                if (! in_array($this->e['type'], ['score_bonus', 'family_buff', 'armed_next'], true) || ! $this->matches($c)) {
                    return;
                }

                $xp = (float) ($this->e['xp'] ?? 0);
                $gold = (float) ($this->e['gold'] ?? 0);
                $boss = (float) ($this->e['boss'] ?? 0);
                $mp = (float) ($this->e['mp'] ?? 0);
                if ($xp == 0 && $gold == 0 && $boss == 0 && $mp == 0) {
                    return;
                }

                $c->xpMultiplier *= 1 + $xp;
                $c->goldMultiplier *= 1 + $gold;
                $c->bossMultiplier *= 1 + $boss;
                $c->mpGain += $mp;
                $c->effects[] = $this->key;

                if ($this->buff?->armed) {
                    $this->buff->delete();
                }
            }

            public function onCronDamage(float $damage): float
            {
                $reduce = match ($this->e['type']) {
                    'cron_shield', 'member_shield' => (float) ($this->buff?->value ?? $this->e['reduce'] ?? 0),
                    'family_buff' => (float) ($this->e['cron'] ?? 0),
                    default => 0.0,
                };

                // Nicht auf 2 Stellen runden: der Wert ist meist ein Faktor (1.0 → 0,375), gerundet wird beim Schaden
                return $reduce > 0 ? round($damage * max(0, 1 - $reduce), 4) : $damage;
            }

            private function matches(ScoreContext $c): bool
            {
                $when = $this->e['when'] ?? [];

                return (! isset($when['categories']) || in_array($c->task->category, $when['categories'], true))
                    && (! isset($when['task_types']) || in_array($c->task->type->value, $when['task_types'], true))
                    && (! isset($when['min_difficulty']) || $c->task->difficulty >= $when['min_difficulty'])
                    && (! isset($when['pool']) || $c->isPoolTask() === (bool) $when['pool'])
                    && (! isset($when['min_streak']) || $c->streak >= $when['min_streak']);
            }
        };
    }

    /** Familien-Buff (Barde „Anfeuern“): +X % Boss-Schaden. */
    public static function bossBuff(ActiveBuff $buff): Modifier
    {
        return new class($buff) extends Modifier
        {
            public function __construct(private readonly ActiveBuff $buff) {}

            public function onScore(ScoreContext $c): void
            {
                $c->bossMultiplier *= 1 + $this->buff->value;
                $c->effects[] = $this->buff->key;
            }
        };
    }

    /**
     * „Scharfe“ Buffs wirken beim nächsten passenden Abschluss und werden dabei verbraucht:
     * Mächtiger Hieb (schwere Aufgabe), Beutezug (Börsen-Aufgabe), Überlastung (Home Assistant).
     */
    public static function armed(ActiveBuff $buff): Modifier
    {
        return new class($buff) extends Modifier
        {
            public function __construct(private readonly ActiveBuff $buff) {}

            public function onScore(ScoreContext $c): void
            {
                $applies = match ($this->buff->key) {
                    'mighty_blow' => $c->task->difficulty >= 2,
                    'loot_run' => $c->isPoolTask(),
                    'overload' => $c->viaHomeAssistant(),
                    default => false,
                };

                if (! $applies) {
                    return;
                }

                match ($this->buff->key) {
                    'mighty_blow' => $c->bossMultiplier *= $this->buff->value,
                    'loot_run' => [$c->goldMultiplier *= 2, $c->goldFlat += 10],
                    'overload' => [$c->xpMultiplier *= $this->buff->value, $c->goldMultiplier *= $this->buff->value],
                };

                $c->effects[] = $this->buff->key;
                $this->buff->delete();
            }
        };
    }
}
