<?php

namespace App\Listeners;

use App\Domain\Dungeons\Torches;
use App\Domain\Events\EventEngine;
use App\Domain\Features\Features;
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
        // Beim Rückgängigmachen kommt der ursprüngliche Abschluss noch einmal an – dann keine Fackeln
        if ($event->result->replayed || $completion->direction !== 'up'
            || in_array($completion->status, [CompletionStatus::Pending, CompletionStatus::Undone, CompletionStatus::Rejected], true)) {
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

        // Fackeln erst ab der Dungeon-Freischaltung, sonst wäre der Vorrat beim Start voll (§13.4)
        if (! Features::has($user, 'dungeons')) {
            return;
        }
        $amount = Torches::forDifficulty((float) $task->difficulty);

        if ($task->type === TaskType::Daily) {
            [$due, $done] = HomeAssistantWebhooks::dailies($user);
            if ($due > 0 && $due === $done && Cache::add("torches:all-dailies:{$user->id}:".$user->gameDate()->toDateString(), true, now()->addDays(2))) {
                $amount += config('game.dungeon.torches.all_dailies');
            }
        }

        // Laternenfest: Die Laterne bringt im Dungeon zusätzliche Fackeln (§12.4)
        $amount += (int) EventEngine::modifier($user->family_id, 'dungeon_torches', 'bonus', 0);

        Torches::award($user, $amount);
    }
}
