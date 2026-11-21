<?php

use App\Domain\Badges\BadgeService;
use App\Models\FeedEntry;
use App\Models\UserBadge;

beforeEach(function () {
    [$this->family, $this->parent, $this->child] = makeFamily();
});

it('vergibt ein Abzeichen genau einmal und meldet es auf Wunsch im Feed', function () {
    $badges = app(BadgeService::class);

    $first = $badges->award($this->child, 'mastery:42', 'mastery', 'Meister: Zähneputzen', '🦷', ['task_id' => 42], 'hat Zähneputzen gemeistert!');
    $second = $badges->award($this->child, 'mastery:42', 'mastery', 'Meister: Zähneputzen', '🦷');

    expect($first)->not->toBeNull()
        ->and($second)->toBeNull()
        ->and(UserBadge::where('user_id', $this->child->id)->count())->toBe(1)
        ->and(FeedEntry::where('family_id', $this->family->id)->where('type', 'badge')->count())->toBe(1)
        ->and(BadgeService::forUser($this->child)[0])->toMatchArray(['name' => 'Meister: Zähneputzen', 'kind_label' => 'Gewohnheit gemeistert', 'new' => true]);

    BadgeService::markSeen($this->child);
    expect(BadgeService::forUser($this->child)[0]['new'])->toBeFalse();
});
