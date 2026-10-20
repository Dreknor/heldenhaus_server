<?php

namespace App\Listeners;

use App\Domain\Dungeons\Torches;
use App\Domain\Integrations\HomeAssistant\HomeAssistantWebhooks;
use App\Enums\CompletionStatus;
use App\Enums\TaskType;
use App\Events\CompletionReviewed;
use App\Events\TaskScored;
use App\Models\TaskCompletion;
use Illuminate\Support\Facades\Cache;

/** Erledigte Aufgaben geben Fackeln für den Dungeon (§2.7); Freigabe-Aufgaben erst nach der Bestätigung. */
class AwardTorches
{
    public function handleTaskScored(TaskScored $event): void
    {
        $completion = $event->result->completion;
        if ($event->result->replayed || $completion->direction !== 'up' || $completion->status === CompletionStatus::Pending) {
            return;
        }
        $this->award($completion);
    }

    public function handleCompletionReviewed(CompletionReviewed $event): void
    {
        if ($event->approved && $event->completion->direction === 'up') {
            $this->award($event->completion);
        }
    }

    private function award(TaskCompletion $completion): void
    {
        $completion->loadMissing('task', 'user');
        $task = $completion->task;
        $user = $completion->user;
        if ($task === null || $user === null || $task->type === TaskType::Reward) {
            return;
        }

        $amount = Torches::forDifficulty((float) $task->difficulty);

        if ($task->type === TaskType::Daily) {
            [$due, $done] = HomeAssistantWebhooks::dailies($user);
            if ($due > 0 && $due === $done && Cache::add("torches:all-dailies:{$user->id}:".$user->gameDate()->toDateString(), true, now()->addDays(2))) {
                $amount += config('game.dungeon.torches.all_dailies');
            }
        }

        Torches::award($user, $amount);
    }
}
