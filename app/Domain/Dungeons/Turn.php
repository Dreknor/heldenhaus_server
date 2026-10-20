<?php

namespace App\Domain\Dungeons;

use App\Domain\Gameplay\GameException;
use App\Models\Dungeon;
use App\Models\DungeonPosition;
use App\Models\User;
use App\Models\UserStats;
use Illuminate\Support\Collection;

/** Zustand eines einzelnen Zugs: Dungeon, Person, Fackeln, Würfel – wird am Ende gemeinsam gespeichert. */
class Turn
{
    public int $spent = 0;

    /** @var array<int, DungeonPosition> andere Positionen, die sich in diesem Zug ändern */
    public array $touched = [];

    private ?Collection $others = null;

    public function __construct(
        public Dungeon $dungeon,
        public User $user,
        public UserStats $stats,
        public DungeonPosition $position,
        public array $state,
        public Rng $rng,
    ) {}

    public function spend(int $torches): void
    {
        Torches::spend($this->stats, $torches);
        $this->spent += $torches;
    }

    public function room(int $id): array
    {
        return $this->state['rooms'][$id] ?? throw new GameException('Diesen Raum gibt es nicht.');
    }

    public function monsterAlive(array $room): bool
    {
        return isset($room['monster']) && $room['monster']['hp'] > 0;
    }

    public function edge(int $a, int $b): ?array
    {
        foreach ($this->state['edges'] as $e) {
            if (($e['a'] === $a && $e['b'] === $b) || ($e['a'] === $b && $e['b'] === $a)) {
                return $e;
            }
        }

        return null;
    }

    public function setEdgeKind(int $a, int $b, string $kind): void
    {
        foreach ($this->state['edges'] as $i => $e) {
            if ((($e['a'] === $a && $e['b'] === $b) || ($e['a'] === $b && $e['b'] === $a)) && $e['kind'] !== $kind) {
                // Die frühere Art merkt sich die Darstellung (offene Tür statt Torbogen)
                $this->state['edges'][$i] = ['kind' => $kind, 'was' => $e['kind']] + $e;
            }
        }
    }

    public function openGates(): void
    {
        foreach ($this->state['edges'] as $i => $e) {
            if ($e['kind'] === 'gate') {
                $this->state['edges'][$i] = ['kind' => 'open', 'was' => 'gate'] + $e;
            }
        }
    }

    /** Nachbarräume über alle Kanten (auch verschlossene) – für das Aufdecken durch Magier. */
    public function neighbors(int $id): array
    {
        return collect($this->state['edges'])->map(fn ($e) => $e['a'] === $id ? $e['b'] : ($e['b'] === $id ? $e['a'] : null))->filter(fn ($n) => $n !== null)->values()->all();
    }

    /** Andere Familienmitglieder in einem Raum. */
    public function othersIn(int $room): Collection
    {
        $this->others ??= DungeonPosition::with('user')->where('dungeon_id', $this->dungeon->id)->where('user_id', '!=', $this->user->id)->lockForUpdate()->get();

        return $this->others->where('room', $room)->values();
    }

    public function touch(DungeonPosition $position): DungeonPosition
    {
        return $this->touched[$position->id] = $position;
    }

    public function class(): ?string
    {
        return $this->stats->class_key;
    }

    public function maxHp(): float
    {
        return DungeonService::maxHpFor($this->stats);
    }
}
