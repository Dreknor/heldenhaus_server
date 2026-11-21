<?php

namespace App\Listeners;

use App\Domain\Goals\GoalService;
use App\Domain\Goals\ReminderService;
use App\Domain\Goals\StarFadeService;
use App\Events\CompletionReviewed;
use App\Events\TaskScored;

/** Ziele (§14.1), Erinnerungen (§14.2) und Ehrensache (§14.3) folgen erledigten und bestätigten Aufgaben. */
class TrackGoals
{
    public function __construct(
        private readonly GoalService $goals,
        private readonly ReminderService $reminders,
        private readonly StarFadeService $fade,
    ) {}

    public function handleScored(TaskScored $event): void
    {
        if ($event->result->replayed) {
            return;
        }

        $completion = $event->result->completion->loadMissing('task', 'user');
        $this->goals->onCompletion($completion);
        $this->reminders->onCompletion($completion);
        $this->fade->onConfirmed($completion);
    }

    public function handleReviewed(CompletionReviewed $event): void
    {
        if ($event->approved) {
            $this->fade->onConfirmed($event->completion->loadMissing('task', 'user')->refresh());
        }
    }
}
