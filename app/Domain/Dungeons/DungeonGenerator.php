<?php

namespace App\Domain\Dungeons;

/**
 * Erzeugt einen Dungeon aus Seed, Art (Familie/Solo), Altersmodus und Thema (Konzept §2.7).
 * Räume liegen auf einem Raster, jeder Raum bekommt einen Tiled-Baustein (`block`), den der Client lädt.
 * Gleicher Seed ⇒ gleicher Dungeon. Alle Räume sind erreichbar: verschlossene Türen und Tore führen nur
 * in Sackgassen, und Schlüssel bzw. Hebel liegen davor.
 *
 * Kanten: open | locked (Schlüssel/Schurke) | gate (Hebel/Tüftler/Home Assistant) | cracked (Krieger, Abkürzung)
 */
class DungeonGenerator
{
    private const DIRECTIONS = [[1, 0], [-1, 0], [0, 1], [0, -1]];

    public static function generate(string $seed, string $kind, string $mode, string $themeKey): array
    {
        $rng = new Rng($seed);
        $theme = DungeonCatalog::theme($themeKey);
        $count = match (true) {
            $kind === 'solo' => 3,
            $mode === 'teen' => $rng->int(9, 12),
            default => $rng->int(6, 8),
        };

        [$rooms, $edges] = self::layout($rng, $count);
        $depth = self::depths($count, $edges);
        $degree = array_fill(0, $count, 0);
        foreach ($edges as $e) {
            $degree[$e['a']]++;
            $degree[$e['b']]++;
        }

        // Ziel: der am weitesten entfernte Raum (bei Gleichstand der mit den wenigsten Türen)
        $candidates = range(1, $count - 1);
        usort($candidates, fn ($a, $b) => [$depth[$b], $degree[$a], $a] <=> [$depth[$a], $degree[$b], $b]);
        $final = $candidates[0];

        $types = array_fill(0, $count, 'monster');
        $types[0] = 'entrance';
        $types[$final] = $kind === 'solo' ? 'treasure' : 'boss';
        $free = array_values(array_diff(range(1, $count - 1), [$final]));

        if ($kind === 'solo') {
            $types[$free[0]] = $rng->pick(['monster', 'trap', 'puzzle']);
        } else {
            $leaves = $rng->shuffle(array_values(array_filter($free, fn ($r) => $degree[$r] === 1)));
            $others = $rng->shuffle(array_values(array_diff($free, $leaves)));
            $order = array_merge($leaves, $others);
            $plan = ['treasure', 'campfire', 'puzzle', 'trap'];
            if ($count >= 7) {
                $plan[] = 'merchant';
            }
            if ($mode === 'teen') {
                array_push($plan, 'trap', 'elite', 'treasure');
            }
            if ($count >= 8) {
                $plan[] = 'treasure';
            }
            foreach ($plan as $i => $type) {
                if (isset($order[$i]) && count(array_keys($types, 'monster')) > 2) {
                    $types[$order[$i]] = $type;
                }
            }
        }

        // Verschlossene Schatzkammer in einer Sackgasse, ein Monster davor trägt den Schlüssel
        $lockedRoom = null;
        if ($kind === 'family') {
            $lockedRoom = collect($free)->first(fn ($r) => $types[$r] === 'treasure' && $degree[$r] === 1);
            if ($lockedRoom !== null) {
                $edges = self::setKind($edges, $lockedRoom, 'locked');
            }
        }

        // Tor mit Hebel ab 8 Räumen: eine weitere Sackgasse, der Hebel liegt in einem anderen Raum
        if ($kind === 'family' && $count >= 8) {
            $gated = collect($free)->first(fn ($r) => $degree[$r] === 1 && $r !== $lockedRoom && $types[$r] !== 'boss');
            $leverRoom = collect($free)->first(fn ($r) => $types[$r] === 'monster' && $r !== $gated && $degree[$r] > 1);
            if ($gated !== null && $leverRoom !== null && count(array_keys($types, 'monster')) > 2) {
                $edges = self::setKind($edges, $gated, 'gate');
                $types[$leverRoom] = 'lever';
            }
        }

        // Rissige Wände als Abkürzung für Teens
        if ($kind === 'family' && $mode === 'teen') {
            $edges = array_merge($edges, self::shortcuts($rng, $rooms, $edges, 2));
        }

        // Schlüssel: bei einem Monster, sonst in einer offenen Truhe (Händler verkaufen ihn außerdem)
        $keyCarrier = $lockedRoom !== null
            ? collect(range(1, $count - 1))->first(fn ($r) => in_array($types[$r], ['monster', 'elite'], true))
                ?? collect(range(1, $count - 1))->first(fn ($r) => $types[$r] === 'treasure' && $r !== $lockedRoom)
            : null;

        $data = [];
        foreach ($rooms as $id => $cell) {
            $data[] = ['id' => $id, 'x' => $cell[0], 'y' => $cell[1], 'type' => $types[$id], 'block' => $rng->pick(DungeonCatalog::blocks($types[$id]))]
                + self::contents($rng, $types[$id], $theme, $mode, $id === $keyCarrier);
        }

        return ['rooms' => $data, 'edges' => array_values($edges), 'revealed' => [0], 'entrance' => 0, 'final' => $final];
    }

    /** Zufällige Ausbreitung auf dem Raster, als Baum verbunden. */
    private static function layout(Rng $rng, int $count): array
    {
        $rooms = [[0, 0]];
        $taken = ['0,0' => 0];
        $edges = [];

        while (count($rooms) < $count) {
            $from = $rng->int(0, count($rooms) - 1);
            [$dx, $dy] = $rng->pick(self::DIRECTIONS);
            $cell = [$rooms[$from][0] + $dx, $rooms[$from][1] + $dy];
            if (isset($taken["{$cell[0]},{$cell[1]}"])) {
                continue;
            }
            $id = count($rooms);
            $rooms[] = $cell;
            $taken["{$cell[0]},{$cell[1]}"] = $id;
            $edges[] = ['a' => $from, 'b' => $id, 'kind' => 'open'];
        }

        return [$rooms, $edges];
    }

    private static function shortcuts(Rng $rng, array $rooms, array $edges, int $max): array
    {
        $linked = collect($edges)->map(fn ($e) => min($e['a'], $e['b']).'-'.max($e['a'], $e['b']))->flip();
        $extra = [];
        foreach ($rng->shuffle(array_keys($rooms)) as $a) {
            foreach ($rooms as $b => $cell) {
                if ($b <= $a || abs($cell[0] - $rooms[$a][0]) + abs($cell[1] - $rooms[$a][1]) !== 1 || isset($linked["{$a}-{$b}"])) {
                    continue;
                }
                $extra[] = ['a' => $a, 'b' => $b, 'kind' => 'cracked'];
                $linked["{$a}-{$b}"] = true;
                if (count($extra) >= $max) {
                    return $extra;
                }
            }
        }

        return $extra;
    }

    private static function setKind(array $edges, int $room, string $kind): array
    {
        return array_map(fn ($e) => $e['a'] === $room || $e['b'] === $room ? ['kind' => $kind] + $e : $e, $edges);
    }

    private static function contents(Rng $rng, string $type, array $theme, string $mode, bool $carriesKey): array
    {
        $monster = fn (string $key, float $factor = 1.0) => ['monster' => [
            'key' => $key, 'hp' => round(DungeonCatalog::monster($key)['hp'] * $factor), 'max_hp' => round(DungeonCatalog::monster($key)['hp'] * $factor),
        ]];
        $easy = $mode === 'child' ? 0.8 : 1.0;

        return match ($type) {
            'monster' => $monster($rng->pick($theme['monsters']), $easy) + ($carriesKey ? ['key_drop' => true] : []),
            'elite' => $monster($theme['elite'], $easy) + ($carriesKey ? ['key_drop' => true] : []),
            'boss' => $monster($theme['boss'], $easy),
            'treasure' => ['chest' => ['opened' => false]] + ($carriesKey ? ['key_drop' => true] : []),
            'trap' => ['trap' => ['sprung' => false, 'difficulty' => $mode === 'child' ? 0.2 : 0.4]],
            'puzzle' => ['puzzle' => self::puzzle($rng, $mode)],
            'campfire' => ['campfire' => ['used' => []]],
            'lever' => ['lever' => ['pulled' => false]],
            default => [],
        };
    }

    /** Einfache Rätsel; für 9–12 leichter. Die Antwort ist immer eine Zahl. */
    public static function puzzle(Rng $rng, string $mode): array
    {
        if ($mode === 'child') {
            if ($rng->chance(0.5)) {
                [$a, $b] = [$rng->int(2, 20), $rng->int(2, 20)];

                return ['question' => "Auf der Tür steht: {$a} + {$b} = ?", 'answer' => (string) ($a + $b), 'hint' => 'Zähle zuerst die größere Zahl, dann weiter.', 'solved' => false];
            }
            [$start, $step] = [$rng->int(1, 10), $rng->int(2, 5)];
            $seq = implode(', ', [$start, $start + $step, $start + 2 * $step, $start + 3 * $step]);

            return ['question' => "Welche Zahl kommt als Nächstes? {$seq}, …", 'answer' => (string) ($start + 4 * $step), 'hint' => "Es kommt immer {$step} dazu.", 'solved' => false];
        }

        if ($rng->chance(0.5)) {
            [$start, $factor] = [$rng->int(1, 5), $rng->int(2, 3)];
            $seq = implode(', ', [$start, $start * $factor, $start * $factor ** 2, $start * $factor ** 3]);

            return ['question' => "Die Runen zeigen: {$seq}, … Welche Zahl folgt?", 'answer' => (string) ($start * $factor ** 4), 'hint' => "Jede Zahl wird mit {$factor} malgenommen.", 'solved' => false];
        }
        [$a, $b, $c] = [$rng->int(3, 9), $rng->int(3, 9), $rng->int(1, 20)];

        return ['question' => "Das Schloss verlangt: {$a} × {$b} − {$c} = ?", 'answer' => (string) ($a * $b - $c), 'hint' => 'Erst malnehmen, dann abziehen.', 'solved' => false];
    }

    /** Kürzeste Entfernung vom Eingang über alle Kanten. */
    public static function depths(int $count, array $edges): array
    {
        $depth = array_fill(0, $count, PHP_INT_MAX);
        $depth[0] = 0;
        $queue = [0];
        while ($queue) {
            $room = array_shift($queue);
            foreach ($edges as $e) {
                $next = $e['a'] === $room ? $e['b'] : ($e['b'] === $room ? $e['a'] : null);
                if ($next !== null && $depth[$next] === PHP_INT_MAX) {
                    $depth[$next] = $depth[$room] + 1;
                    $queue[] = $next;
                }
            }
        }

        return $depth;
    }

    /** Erreichbare Räume vom Eingang; $kinds = erlaubte Kantenarten. */
    public static function reachable(array $state, array $kinds = ['open']): array
    {
        $seen = [0 => true];
        $queue = [0];
        while ($queue) {
            $room = array_shift($queue);
            foreach ($state['edges'] as $e) {
                if (! in_array($e['kind'], $kinds, true)) {
                    continue;
                }
                $next = $e['a'] === $room ? $e['b'] : ($e['b'] === $room ? $e['a'] : null);
                if ($next !== null && ! isset($seen[$next])) {
                    $seen[$next] = true;
                    $queue[] = $next;
                }
            }
        }
        ksort($seen);

        return array_keys($seen);
    }
}
