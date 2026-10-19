<?php

use App\Domain\Gameplay\Formulas;
use App\Domain\Gameplay\Progression;
use App\Enums\AgeGroup;
use App\Models\UserStats;

it('berechnet delta wie im Original und begrenzt den Wert', function () {
    expect(Formulas::delta(0))->toBe(1.0)
        ->and(Formulas::delta(10))->toBeLessThan(1)
        ->and(Formulas::delta(-10))->toBeGreaterThan(1)
        ->and(Formulas::delta(-1000))->toBe(Formulas::delta(-47.27))
        ->and(Formulas::clampValue(100))->toBe(21.27);
});

it('berechnet die XP-Schwellen pro Level', function () {
    expect(Formulas::xpToNextLevel(1))->toBe(150)
        ->and(Formulas::xpToNextLevel(2))->toBe(160)
        ->and(Formulas::xpToNextLevel(10))->toBe(260);
});

it('gibt für schwere Aufgaben mehr XP und Gold', function () {
    expect(Formulas::xp(1, 2))->toBe(12)
        ->and(Formulas::xp(1, 1))->toBe(6)
        ->and(Formulas::gold(1, 2))->toBeGreaterThan(Formulas::gold(1, 1))
        ->and(Formulas::gold(1, 1, 0, 50))->toBe(1.5);
});

it('dämpft den Schaden für Kinder', function () {
    $child = Formulas::damage(1, 1, 0, Formulas::ageDamageFactor(AgeGroup::Child));
    $adult = Formulas::damage(1, 1, 0, Formulas::ageDamageFactor(AgeGroup::Adult));

    expect($child)->toBe(1.0)->and($adult)->toBe(2.0);
});

it('steigt mehrere Level auf einmal auf', function () {
    $stats = new UserStats(['xp' => 0, 'level' => 1, 'hp' => 10, 'unspent_points' => 0]);

    $levelUps = Progression::gainXp($stats, 320);

    expect($levelUps)->toBe(2)
        ->and($stats->level)->toBe(3)
        ->and($stats->xp)->toBe(10)
        ->and($stats->hp)->toBe(50.0);
});

it('verliert bei K.O. kein Level, füllt HP auf und nimmt optional Gold', function () {
    $stats = new UserStats(['hp' => 3, 'gold' => 100, 'level' => 5]);

    $ko = Progression::takeDamage($stats, 5);

    expect($ko)->toBeTrue()
        ->and($stats->level)->toBe(5)
        ->and($stats->hp)->toBe(50.0)
        ->and($stats->gold)->toBe(100.0);
});

it('ordnet Altersgruppen nach Geburtsjahr zu', function () {
    expect(AgeGroup::fromBirthYear(2016, 2026))->toBe(AgeGroup::Child)
        ->and(AgeGroup::fromBirthYear(2012, 2026))->toBe(AgeGroup::Teen)
        ->and(AgeGroup::fromBirthYear(1985, 2026))->toBe(AgeGroup::Adult)
        ->and(AgeGroup::fromBirthYear(null))->toBe(AgeGroup::Adult);
});
