<?php

namespace App\Domain\Dungeons;

use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Deterministischer Zufall (§2.7, §9): Seed + Aktionszähler → immer dasselbe Ergebnis.
 * So ist jeder Dungeon und jeder Kampf reproduzierbar und lässt sich nicht durch Wiederholen erzwingen.
 */
class Rng
{
    private Randomizer $random;

    public function __construct(string $seed, int|string $counter = 'map')
    {
        $this->random = new Randomizer(new Mt19937(crc32("{$seed}:{$counter}")));
    }

    public function int(int $min, int $max): int
    {
        return $this->random->getInt($min, $max);
    }

    public function float(): float
    {
        return $this->random->nextFloat();
    }

    public function chance(float $p): bool
    {
        return $this->float() < $p;
    }

    public function pick(array $values): mixed
    {
        $values = array_values($values);

        return $values[$this->int(0, count($values) - 1)];
    }

    /** @param  array<string, int>  $weights */
    public function weighted(array $weights): string
    {
        $roll = $this->int(1, max(1, array_sum($weights)));
        foreach ($weights as $key => $weight) {
            $roll -= $weight;
            if ($roll <= 0) {
                return (string) $key;
            }
        }

        return (string) array_key_first($weights);
    }

    /** ±20 % Streuung */
    public function vary(float $value): float
    {
        return $value * (0.8 + 0.4 * $this->float());
    }

    public function shuffle(array $values): array
    {
        return $this->random->shuffleArray(array_values($values));
    }
}
