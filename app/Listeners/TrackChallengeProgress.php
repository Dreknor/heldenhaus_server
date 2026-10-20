<?php

namespace App\Listeners;

use App\Domain\Social\ChallengeService;
use App\Events\TaskScored;

class TrackChallengeProgress
{
    public function __construct(private readonly ChallengeService $challenges) {}

    public function handle(TaskScored $event): void
    {
        if (! $event->result->replayed) {
            $this->challenges->progress($event->result->completion->loadMissing('task'));
        }
    }
}
