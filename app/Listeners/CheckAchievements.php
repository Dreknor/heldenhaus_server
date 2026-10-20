<?php

namespace App\Listeners;

use App\Domain\Achievements\AchievementMetrics;
use App\Domain\Achievements\AchievementService;
use App\Events\TaskScored;

class CheckAchievements
{
    public function __construct(private readonly AchievementService $achievements) {}

    public function handle(TaskScored $event): void
    {
        $completion = $event->result->completion;

        if ($event->result->replayed || $completion->direction !== 'up') {
            return;
        }

        $this->achievements->evaluate($completion->user, AchievementMetrics::TASKS);
    }
}
