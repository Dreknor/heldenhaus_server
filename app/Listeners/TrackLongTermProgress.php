<?php

namespace App\Listeners;

use App\Domain\Achievements\AlbumService;
use App\Domain\Achievements\ChronicleService;
use App\Domain\Achievements\HeroYearService;
use App\Domain\Achievements\MasteryService;
use App\Domain\Achievements\TitleService;
use App\Enums\CompletionStatus;
use App\Enums\TaskType;
use App\Events\CompletionReviewed;
use App\Events\LevelUp;
use App\Events\QuestCompleted;
use App\Events\TaskScored;
use App\Models\TaskCompletion;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Langzeitziele (§14.7, §14.8): Jahrespunkte und Meisterschaften beim Abhaken (Freigabe-Aufgaben erst nach
 * der Bestätigung), Titel und Chronik beim Level-Aufstieg, Monster-Lexikon und Chronik nach einem Boss.
 */
class TrackLongTermProgress
{
    public function __construct(
        private readonly HeroYearService $heroYear,
        private readonly MasteryService $masteries,
        private readonly AlbumService $albums,
        private readonly TitleService $titles,
        private readonly ChronicleService $chronicle,
    ) {}

    public function handleTaskScored(TaskScored $event): void
    {
        $completion = $event->result->completion;
        if ($event->result->replayed || $completion->direction !== 'up'
            || in_array($completion->status, [CompletionStatus::Pending, CompletionStatus::Undone, CompletionStatus::Rejected], true)) {
            return;
        }
        $this->counted($completion);
    }

    public function handleCompletionReviewed(CompletionReviewed $event): void
    {
        if ($event->approved && $event->completion->direction === 'up') {
            $this->counted($event->completion);
        }
    }

    public function handleLevelUp(LevelUp $event): void
    {
        $this->titles->sync($event->user, $event->level);
        $this->chronicle->sync($event->user);
    }

    public function handleQuestCompleted(QuestCompleted $event): void
    {
        $quest = $event->quest;
        if ($quest->type !== 'boss') {
            return;
        }
        $members = $quest->scope === 'family' && $quest->family_id
            ? User::where('family_id', $quest->family_id)->get()
            : User::whereKey($quest->user_id)->get();

        foreach ($members as $member) {
            $this->chronicle->sync($member);
            $this->albums->sync($member);
        }
    }

    private function counted(TaskCompletion $completion): void
    {
        $completion->loadMissing('task', 'user');
        if ($completion->task === null || $completion->user === null || $completion->task->type === TaskType::Reward) {
            return;
        }

        $this->heroYear->awardCompletion($completion);
        $this->masteries->refresh($completion->user, $completion->task->category);

        // Das Album zählt viele Tabellen – höchstens alle 10 Minuten beim Abhaken nachsehen
        if (Cache::add("album-sync:{$completion->user_id}", true, now()->addMinutes(10))) {
            $this->albums->sync($completion->user);
        }
    }
}
