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
