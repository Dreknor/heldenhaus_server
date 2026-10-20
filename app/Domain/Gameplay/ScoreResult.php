<?php

namespace App\Domain\Gameplay;

use App\Models\Item;
use App\Models\TaskCompletion;
use App\Models\UserStats;

final class ScoreResult
{
    /** Event-Fortschritt (§12): Punkte, Fund, erreichte Stufen – gesetzt nach dem Abschluss */
    public ?array $event = null;

    /** Zufallsereignis nur zum Spaß (§12.5) */
    public ?array $funEvent = null;

    public function __construct(
        public readonly TaskCompletion $completion,
        public readonly UserStats $stats,
        public readonly int $levelUps = 0,
        public readonly bool $knockedOut = false,
        public readonly bool $replayed = false,
        public readonly ?Item $drop = null,
    ) {}

    public function toArray(): array
    {
        return [
            'completion_id' => $this->completion->id,
            'status' => $this->completion->status->value,
            'xp' => $this->completion->xp,
            'gold' => $this->completion->gold,
            'hp' => $this->completion->hp,
            'stars' => $this->completion->stars,
            'level_ups' => $this->levelUps,
            'knocked_out' => $this->knockedOut,
            'drop' => $this->drop?->only(['id', 'key', 'name', 'icon', 'rarity', 'type']),
            'surprise' => $this->completion->surprise,
            'event' => $this->event,
            'fun_event' => $this->funEvent,
            'stats' => $this->stats->only(['hp', 'mp', 'xp', 'gold', 'stars', 'level']),
        ];
    }
}
