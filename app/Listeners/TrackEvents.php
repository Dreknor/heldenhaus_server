<?php

namespace App\Listeners;

use App\Domain\Events\EventCatalog;
use App\Domain\Events\EventEngine;
use App\Domain\Events\EventTrigger;
use App\Domain\Events\FunEventService;
use App\Enums\CompletionStatus;
use App\Events\BuildProjectCompleted;
use App\Events\CompletionReviewed;
use App\Events\MemberJoined;
use App\Events\QuestCompleted;
use App\Events\TaskScored;
use App\Models\FamilyEvent;
use App\Models\Quest;
use App\Models\TaskCompletion;
use App\Models\User;

/**
 * Events (§12): Punkte und Funde aus erledigten Aufgaben (Freigabe-Aufgaben erst nach der Bestätigung)
 * und ereignisgesteuerte Auslöser aus den Domain-Events.
 */
class TrackEvents
{
    public function __construct(private readonly EventEngine $engine, private readonly EventTrigger $trigger) {}

    public function handleTaskScored(TaskScored $event): void
    {
        $completion = $event->result->completion;
        // Auch beim Rückgängigmachen kommt der ursprüngliche Abschluss hier an – dann nichts neu werten
        if ($event->result->replayed || $completion->direction !== 'up'
            || in_array($completion->status, [CompletionStatus::Pending, CompletionStatus::Undone, CompletionStatus::Rejected], true)) {
            return;
        }
        $event->result->event = $this->engine->score($completion);

        // Erste Aktivität nach längerer Pause → sanfter Wiedereinstieg, ohne Hinweis auf die verpassten Tage
        $previous = TaskCompletion::where('user_id', $completion->user_id)->where('direction', 'up')->where('id', '<', $completion->id)->latest('id')->value('created_at');
        if ($previous && $previous->lte(now()->subDays((int) config('game.events.comeback_pause_days')))) {
            self::comeback($completion->user);
        }
    }

    public function handleCompletionReviewed(CompletionReviewed $event): void
    {
        if ($event->approved && $event->completion->direction === 'up') {
            $this->engine->score($event->completion);
        }
    }

    public function handleQuestCompleted(QuestCompleted $event): void
    {
        $quest = $event->quest;

        // Belohnung eines Events für seine Quest (Drachenschuppen beim Drachenfürsten)
        $running = EventEngine::running($quest->family_id)->first(fn (FamilyEvent $e) => $e->quest_id === $quest->id);
        if ($running && isset($running->definition()['rewards_on_quest'])) {
            foreach (EventEngine::members($running) as $member) {
                $this->engine->reward($member, $running->definition()['rewards_on_quest'], EventEngine::participation($running, $member->id), $running);
            }
        }

        if ($quest->scope === 'family' && $quest->isBoss()) {
            // Drei Familien-Bosse in Folge besiegt → der Drachenfürst kommt
            $count = (int) (EventCatalog::get('dragon_lord')['trigger']['count'] ?? 3);
            $last = Quest::where('family_id', $quest->family_id)->where('scope', 'family')->where('type', 'boss')
                ->whereIn('status', ['completed', 'expired', 'abandoned'])->latest('id')->limit($count)->pluck('status');
            if ($last->count() >= $count && $last->every(fn ($s) => $s === 'completed') && $quest->quest_key !== 'dragon_lord') {
                $this->trigger->consider($quest->family_id, 'dragon_lord', ['source' => 'game']);
            }
        }

        if ($quest->isBoss()) {
            app(FunEventService::class)->monsterPost($quest);
        }
    }

    public function handleMemberJoined(MemberJoined $event): void
    {
        $user = $event->user;
        // Nur in einer schon eingespielten Familie, nicht beim Einrichten am ersten Tag
        if ($user->family_id && $user->family && $user->family->created_at->lt(now()->subDays(3))) {
            $this->trigger->consider($user->family_id, 'welcome_party', ['source' => 'game', 'subject_user_id' => $user->id]);
        }
    }

    public function handleBuildProjectCompleted(BuildProjectCompleted $event): void
    {
        if (isset($event->project['effects']['stage'])) {
            $this->trigger->consider($event->build->family_id, 'house_warming', ['source' => 'game']);
        }
    }

    /** Willkommen zurück (§12.4, nur persönlich): nach ≥ 5 Tagen Pause oder ≥ 3 Tagen im Gasthaus. */
    public static function comeback(User $user): void
    {
        if ($user->family_id) {
            app(EventTrigger::class)->consider($user->family_id, 'welcome_back', ['source' => 'game', 'subject_user_id' => $user->id]);
        }
    }
}
