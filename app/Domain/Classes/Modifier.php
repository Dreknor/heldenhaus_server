<?php

namespace App\Domain\Classes;

/**
 * Ein Glied der Modifikator-Kette (Konzept §2.4): Klassen-Passive und aktive Buffs
 * melden sich hier an und verändern Abschlüsse bzw. Schaden beim Tageswechsel.
 */
abstract class Modifier
{
    /** Positiver Abschluss (Daily/To-do/Gewohnheit +). */
    public function onScore(ScoreContext $context): void {}

    /** Schaden beim Tageswechsel; gibt den angepassten Schaden zurück. */
    public function onCronDamage(float $damage): float
    {
        return $damage;
    }
}
