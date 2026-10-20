<?php

use App\Domain\Quests\QuestService;
use App\Enums\CompletionStatus;
use App\Models\TaskCompletion;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    [$this->family, $this->parent, $this->child] = makeFamily();
});

test('ha-zustand enthält streak, offene freigaben, quest und avatar-version', function () {
    makeTask($this->parent, [
        'scope' => 'family', 'type' => 'daily', 'title' => 'Zimmer aufräumen', 'assignment_mode' => 'assigned',
        'assignees' => [$this->child->id], 'approval_mode' => 'required',
    ]);
    $id = assignmentOf(\App\Models\Task::first(), $this->child)->id;
    Sanctum::actingAs($this->parent, ['ha']);

    $this->postJson("/api/v1/ha/members/{$this->child->id}/assignments/{$id}/score/up")->assertOk();

    $state = $this->getJson('/api/v1/ha/state')->assertOk();
    $lea = collect($state->json('members'))->firstWhere('name', 'Lea');

    expect($state->json('pending_approvals.completions'))->toBe(1)
        ->and($state->json('quest'))->toBeNull()
        ->and($lea['pending_approvals'])->toBe(1)
        ->and($lea['streak'])->toBeInt()
        ->and($lea['avatar_version'])->toBeString()->toHaveLength(12);
});

test('home assistant spielt stellvertretend für ein kind – mit normalen freigaben', function () {
    makeTask($this->parent, [
        'scope' => 'family', 'type' => 'todo', 'title' => 'Müll rausbringen', 'assignment_mode' => 'assigned',
        'assignees' => [$this->child->id], 'approval_mode' => 'required', 'star_reward' => 3,
    ]);
    makeTask($this->child, ['type' => 'habit', 'title' => 'Wasser trinken', 'up' => true, 'down' => false]);
    Sanctum::actingAs($this->parent, ['ha']);

    $board = $this->getJson("/api/v1/ha/members/{$this->child->id}/board")->assertOk();
    expect($board->json('todos.0.title'))->toBe('Müll rausbringen')
        ->and($board->json('habits.0.title'))->toBe('Wasser trinken');

    $todo = $board->json('todos.0.assignment_id');
    $habit = $board->json('habits.0.assignment_id');

    $this->postJson("/api/v1/ha/members/{$this->child->id}/assignments/{$todo}/score/up")->assertOk();
    $this->postJson("/api/v1/ha/members/{$this->child->id}/assignments/{$habit}/score/up")->assertOk();

    $completion = TaskCompletion::where('assignment_id', $todo)->first();
    // Ein Klick in HA ist kein Sensor-Nachweis: Quelle „app“, Freigabe nötig.
    expect($completion->user_id)->toBe($this->child->id)
        ->and($completion->source)->toBe('app')
        ->and($completion->status)->toBe(CompletionStatus::Pending)
        ->and($this->child->stats()->first()->xp)->toBeGreaterThan(0);
});

test('fremde aufgaben und fremde familien sind tabu', function () {
    [, $otherParent, $otherChild] = makeFamily();
    $task = makeTask($this->parent, ['title' => 'Elternaufgabe']);
    $parentAssignment = assignmentOf($task, $this->parent)->id;
    Sanctum::actingAs($this->parent, ['ha']);

    // Kind darf die Aufgabe der Eltern nicht abhaken
    $this->postJson("/api/v1/ha/members/{$this->child->id}/assignments/{$parentAssignment}/score/up")->assertForbidden();
    // Mitglied einer anderen Familie gibt es aus Sicht dieses Tokens nicht
    $this->getJson("/api/v1/ha/members/{$otherChild->id}/board")->assertNotFound();
});

test('über home assistant gibt es keine eltern-funktionen', function () {
    Sanctum::actingAs($this->parent, ['ha']);

    $this->getJson("/api/v1/ha/members/{$this->child->id}/approvals")->assertNotFound();
    $this->postJson("/api/v1/ha/members/{$this->child->id}/tasks", ['title' => 'x'])->assertNotFound();

    Sanctum::actingAs($this->parent, ['app']);
    $this->getJson("/api/v1/ha/members/{$this->child->id}/board")->assertForbidden();
});

test('kinder lösen belohnungen über home assistant ein', function () {
    $item = $this->family->rewardItems()->create(['title' => '30 min Konsole', 'star_cost' => 2]);
    $this->child->stats()->update(['stars' => 5]);
    Sanctum::actingAs($this->parent, ['ha']);

    $this->getJson("/api/v1/ha/members/{$this->child->id}/shop")
        ->assertOk()->assertJsonPath('items.0.title', '30 min Konsole')->assertJsonPath('stars', 5);

    $this->postJson("/api/v1/ha/members/{$this->child->id}/shop/items/{$item->id}/redeem")
        ->assertOk()->assertJsonPath('stars', 3);
});

test('avatar und quest-bild kommen als svg', function () {
    $this->child->update(['avatar' => [
        'skin' => '#f6c89f', 'hair' => 'curly', 'hair_color' => '#c0392b', 'eyes' => 'wink', 'outfit' => '#3498db', 'background' => '#bfdbfe',
    ]]);
    Sanctum::actingAs($this->parent, ['ha']);

    $avatar = $this->get("/api/v1/ha/members/{$this->child->id}/avatar.svg")->assertOk();
    expect($avatar->headers->get('Content-Type'))->toStartWith('image/svg+xml')
        ->and($avatar->getContent())->toStartWith('<svg')->toContain('#c0392b')->toEndWith('</svg>');

    $this->get('/api/v1/ha/quest.svg')->assertNotFound();

    app(QuestService::class)->start($this->parent, 'dust_monster', 'family');
    $quest = $this->get('/api/v1/ha/quest.svg')->assertOk();
    expect($quest->getContent())->toStartWith('<svg')->toContain('viewBox="0 0 160 150"');

    expect($this->getJson('/api/v1/ha/state')->json('quest.name'))->not->toBeNull();
});

test('avatar-svg lässt keine fremden werte in die grafik', function () {
    $svg = \App\Domain\Art\AvatarSvg::render(
        ['skin' => '"/><script>alert(1)</script>', 'hair' => 'long"onload="x', 'outfit' => 'red'],
        [],
        7,
    );

    expect($svg)->not->toContain('<script')->not->toContain('onload');
});
