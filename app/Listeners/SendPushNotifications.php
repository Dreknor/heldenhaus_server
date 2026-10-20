<?php

namespace App\Listeners;

use App\Domain\Notifications\PushNotifier;
use App\Enums\CompletionStatus;
use App\Enums\FamilyRole;
use App\Events\CompletionReviewed;
use App\Events\LevelUp;
use App\Events\QuestCompleted;
use App\Events\RedemptionRequested;
use App\Events\RewardRedeemed;
use App\Events\TaskScored;
use App\Models\User;

class SendPushNotifications
{
    public function __construct(private readonly PushNotifier $push) {}

    public function handleTaskScored(TaskScored $event): void
    {
        $completion = $event->result->completion;
        if ($event->result->replayed || $completion->status !== CompletionStatus::Pending) {
            return;
        }

        $user = $completion->user;
        $this->push->notify(PushNotifier::parentsOf($user->family_id)->where('id', '!=', $user->id), 'approval_pending', ['completion_id' => $completion->id]);
    }

    public function handleRedemptionRequested(RedemptionRequested $event): void
    {
        $user = $event->redemption->user;
        $this->push->notify(PushNotifier::parentsOf($user->family_id)->where('id', '!=', $user->id), 'redemption_pending', ['redemption_id' => $event->redemption->id]);
    }

    public function handleCompletionReviewed(CompletionReviewed $event): void
    {
        // Auto-Freigaben nach Fristablauf melden wir nicht – das wäre nur Rauschen.
        if ($event->completion->reviewed_by === null) {
            return;
        }

        $this->push->notify($event->completion->user, 'approval_reviewed', ['completion_id' => $event->completion->id, 'approved' => $event->approved ? '1' : '0']);
    }

    public function handleRewardRedeemed(RewardRedeemed $event): void
    {
        if ($event->redemption->reviewed_by !== null) {
            $this->push->notify($event->redemption->user, 'redemption_reviewed', ['redemption_id' => $event->redemption->id]);
        }
    }

    public function handleLevelUp(LevelUp $event): void
    {
        $this->push->notify($event->user, 'level_up', ['level' => $event->level]);
    }

    public function handleQuestCompleted(QuestCompleted $event): void
    {
        $quest = $event->quest;
        $users = $quest->scope === 'solo'
            ? User::whereKey($quest->user_id)->get()
            : User::where('family_id', $quest->family_id)->where('family_role', '!=', FamilyRole::Guest->value)->get();

        $this->push->notify($users, 'quest_completed', ['quest_id' => $quest->id]);
    }
}
