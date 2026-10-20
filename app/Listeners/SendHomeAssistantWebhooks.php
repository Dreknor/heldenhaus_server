<?php

namespace App\Listeners;

use App\Domain\Integrations\HomeAssistant\HomeAssistantWebhooks;
use App\Enums\CompletionStatus;
use App\Enums\TaskType;
use App\Events\LevelUp;
use App\Events\QuestCompleted;
use App\Events\RewardRedeemed;
use App\Events\TaskScored;
use Illuminate\Support\Facades\Cache;

/** Spielereignisse → Webhooks an Home Assistant. Private Aufgaben verraten ihren Titel nicht. */
class SendHomeAssistantWebhooks
{
    public function __construct(private readonly HomeAssistantWebhooks $webhooks) {}

    public function handleTaskScored(TaskScored $event): void
    {
        $completion = $event->result->completion;
        if ($event->result->replayed || $completion->direction !== 'up') {
            return;
        }

        $completion->loadMissing('task', 'user');
        $task = $completion->task;
        $user = $completion->user;

        $data = HomeAssistantWebhooks::member($user) + [
            'task_id' => $task->id,
            'task_title' => $task->is_private ? null : $task->title,
            'task_type' => $task->type->value,
            'category' => $task->is_private ? null : $task->category,
            'family_task' => $task->isFamilyTask(),
            'source' => $completion->source,
            'status' => $completion->status->value,
            'xp' => $completion->xp,
            'gold' => (float) $completion->gold,
            'stars' => $completion->stars,
        ];

        $this->webhooks->send($user->family_id, 'task_completed', $data);

        if ($completion->status === CompletionStatus::Pending) {
            $this->webhooks->send($user->family_id, 'approval_requested', $data);
        }

        if ($task->type === TaskType::Daily) {
            [$due, $done] = HomeAssistantWebhooks::dailies($user);
            $key = "ha:all-dailies:{$user->id}:".$user->gameDate()->toDateString();

            if ($due > 0 && $due === $done && Cache::add($key, true, now()->addDays(2))) {
                $this->webhooks->send($user->family_id, 'all_dailies_done', HomeAssistantWebhooks::member($user) + ['dailies' => $due]);
            }
        }
    }

    public function handleLevelUp(LevelUp $event): void
    {
        $this->webhooks->send($event->user->family_id, 'level_up', HomeAssistantWebhooks::member($event->user) + ['level' => $event->level]);
    }

    public function handleRewardRedeemed(RewardRedeemed $event): void
    {
        $redemption = $event->redemption->loadMissing('rewardItem', 'user');
        $item = $redemption->rewardItem;

        $this->webhooks->send($item->family_id, 'reward_redeemed', HomeAssistantWebhooks::member($redemption->user) + [
            'redemption_id' => $redemption->id,
            'reward_id' => $item->id,
            'reward_title' => $item->title,
            'stars' => $redemption->stars,
            'action_key' => $item->ha_action_key,
        ]);
    }

    public function handleQuestCompleted(QuestCompleted $event): void
    {
        $quest = $event->quest;

        $this->webhooks->send($quest->family_id, $quest->isBoss() ? 'boss_defeated' : 'quest_completed', [
            'quest_id' => $quest->id,
            'quest_key' => $quest->quest_key,
            'name' => $quest->name,
            'scope' => $quest->scope,
            'member_id' => $quest->scope === 'solo' ? $quest->user_id : null,
            'reward_text' => $quest->reward_text,
        ]);
    }
}
