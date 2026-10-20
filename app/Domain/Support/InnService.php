<?php

namespace App\Domain\Support;

use App\Domain\Gameplay\GameException;
use App\Listeners\TrackEvents;
use App\Models\User;
use App\Models\UserStats;

/**
 * Gasthaus (§1, §11): Pausen-, Krank- und Ferienmodus. Im Gasthaus gibt es keinen Schaden beim Tageswechsel,
 * Serien und Bosse ruhen. Kinder schicken die Eltern hinein, Teens und Erwachsene entscheiden selbst.
 * Wer nach mindestens 3 Tagen zurückkommt, bekommt „Willkommen zurück“ (§12.4).
 */
class InnService
{
    public function enter(User $actor, User $target): UserStats
    {
        $this->ensureAllowed($actor, $target);
        $stats = $target->stats()->firstOrFail();
        if ($stats->sleeping) {
            throw new GameException('Schon im Gasthaus. Gute Erholung!');
        }
        $stats->forceFill(['sleeping' => true, 'sleeping_since' => now()])->save();

        return $stats;
    }

    public function leave(User $actor, User $target): UserStats
    {
        $this->ensureAllowed($actor, $target);
        $stats = $target->stats()->firstOrFail();
        if (! $stats->sleeping) {
            throw new GameException('Du bist gar nicht im Gasthaus.');
        }
        $since = $stats->sleeping_since;
        $stats->forceFill(['sleeping' => false, 'sleeping_since' => null])->save();

        if ($since && $since->lte(now()->subDays((int) config('game.events.comeback_inn_days')))) {
            TrackEvents::comeback($target);
        }

        return $stats;
    }

    private function ensureAllowed(User $actor, User $target): void
    {
        $allowed = $actor->id === $target->id || ($actor->isParent() && $actor->sameFamilyAs($target) && ! $target->isParent());
        if (! $allowed) {
            throw new GameException('Das Gasthaus wählt jede Person selbst, für Kinder auch die Eltern.');
        }
    }
}
