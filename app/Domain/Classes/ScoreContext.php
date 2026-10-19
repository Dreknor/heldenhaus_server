<?php

namespace App\Domain\Classes;

use App\Models\Task;
use App\Models\User;
use App\Models\UserStats;
use Random\Randomizer;

/** Veränderbarer Zustand eines Abschlusses, den die Modifikatoren der Reihe nach bearbeiten. */
final class ScoreContext
{
    public float $xpMultiplier = 1.0;

    public float $goldMultiplier = 1.0;

    public float $goldFlat = 0.0;

    public float $bossMultiplier = 1.0;

    public float $mpGain = 0.0;

    /** @var list<string> Schlüssel der Effekte, die gewirkt haben (für Anzeige und Statistik) */
    public array $effects = [];

    public function __construct(
        public readonly User $user,
        public readonly UserStats $stats,
        public readonly Task $task,
        public readonly string $source,
        public readonly Randomizer $random,
    ) {}

    public function isPoolTask(): bool
    {
        return $this->task->isPool();
    }

    public function viaHomeAssistant(): bool
    {
        return $this->source === 'ha';
    }
}
