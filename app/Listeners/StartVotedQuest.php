<?php

namespace App\Listeners;

use App\Domain\Council\VoteService;
use App\Events\QuestCompleted;
use App\Models\Vote;

/** Ist eine Familien-Quest vorbei, startet die in der Abstimmung gewählte nächste Quest (§14.4). */
class StartVotedQuest
{
    public function __construct(private readonly VoteService $votes) {}

    public function handle(QuestCompleted $event): void
    {
        if ($event->quest->scope !== 'family') {
            return;
        }
        Vote::where('family_id', $event->quest->family_id)->where('status', 'decided')->get()
            ->each(fn (Vote $v) => $this->votes->startWinner($v));
    }
}
