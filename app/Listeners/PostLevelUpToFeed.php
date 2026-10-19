<?php

namespace App\Listeners;

use App\Domain\Social\FeedService;
use App\Events\LevelUp;

class PostLevelUpToFeed
{
    public function __construct(private readonly FeedService $feed) {}

    public function handle(LevelUp $event): void
    {
        $this->feed->post($event->user->family_id, $event->user, 'level_up', '⬆️', "{$event->user->name} hat Level {$event->level} erreicht!");
    }
}
