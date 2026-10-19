<?php

use App\Domain\Gameplay\GameException;
use App\Domain\Gameplay\ScoreTask;
use App\Domain\Rewards\RewardService;
use App\Domain\Tasks\ReviewCompletion;
use App\Domain\Tasks\TaskService;
use App\Enums\CompletionStatus;
use App\Models\TaskCompletion;

beforeEach(function () {
    [$this->family, $this->parent, $this->child] = makeFamily();
    $this->score = app(ScoreTask::class);
});

it('legt Familienaufgaben mit Zuweisung an', function () {
    $task = makeTask($this->parent, [
        'scope' => 'family', 'assignment_mode' => 'assigned', 'assignees' => [$this->child->id], 'star_reward' => 3,
    ]);

    expect($task->family_id)->toBe($this->family->id)
        ->and($task->assignments()->pluck('user_id')->all())->toBe([$this->child->id]);
});

it('ignoriert Zuweisungen an fremde Familien', function () {
    [, , $foreignChild] = makeFamily();

    $task = makeTask($this->parent, [
        'scope' => 'family', 'assignment_mode' => 'assigned', 'assignees' => [$this->child->id, $foreignChild->id],
    ]);

    expect($task->assignments()->count())->toBe(1);
});

it('vergibt Sterne erst nach Freigabe', function () {
    $task = makeTask($this->parent, [
        'scope' => 'family', 'assignment_mode' => 'assigned', 'assignees' => [$this->child->id],
        'star_reward' => 3, 'approval_mode' => 'required',
    ]);

    $result = ($this->score)(assignmentOf($task, $this->child), 'up');
    expect($result->completion->status)->toBe(CompletionStatus::Pending)
        ->and($this->child->stats()->first()->stars)->toBe(0);

    app(ReviewCompletion::class)->approve($result->completion, $this->parent);

    expect($this->child->stats()->first()->stars)->toBe(3)
        ->and($result->completion->refresh()->status)->toBe(CompletionStatus::Approved);
});

it('vergibt Sterne sofort ohne Freigabe-Pflicht', function () {
    $task = makeTask($this->parent, [
        'scope' => 'family', 'assignment_mode' => 'assigned', 'assignees' => [$this->child->id], 'star_reward' => 2,
    ]);

    ($this->score)(assignmentOf($task, $this->child), 'up');

    expect($this->child->stats()->first()->stars)->toBe(2);
});

it('macht bei Ablehnung alles rückgängig', function () {
    $task = makeTask($this->parent, [
        'scope' => 'family', 'assignment_mode' => 'assigned', 'assignees' => [$this->child->id],
        'star_reward' => 3, 'approval_mode' => 'required',
    ]);
    $result = ($this->score)(assignmentOf($task, $this->child), 'up');

    app(ReviewCompletion::class)->reject($result->completion, $this->parent, 'Bitte nochmal');

    $stats = $this->child->stats()->first();
    expect($stats->xp)->toBe(0)
        ->and($stats->gold)->toBe(0.0)
        ->and($stats->stars)->toBe(0)
        ->and(assignmentOf($task, $this->child)->completed)->toBeFalse()
        ->and($result->completion->refresh()->review_comment)->toBe('Bitte nochmal');
});

it('bestätigt offene Freigaben nach Ablauf der Frist automatisch', function () {
    $task = makeTask($this->parent, [
        'scope' => 'family', 'assignment_mode' => 'assigned', 'assignees' => [$this->child->id],
        'star_reward' => 4, 'approval_mode' => 'required',
    ]);
    ($this->score)(assignmentOf($task, $this->child), 'up');

    $this->travel(25)->hours();
    $count = app(ReviewCompletion::class)->autoApproveExpired();

    expect($count)->toBe(1)
        ->and(TaskCompletion::first()->status)->toBe(CompletionStatus::Auto)
        ->and($this->child->stats()->first()->stars)->toBe(4);
});

it('wertet Home-Assistant-Abschlüsse als verifiziert', function () {
    $task = makeTask($this->parent, [
        'scope' => 'family', 'assignment_mode' => 'assigned', 'assignees' => [$this->child->id],
        'star_reward' => 1, 'approval_mode' => 'required',
    ]);

    $result = ($this->score)(assignmentOf($task, $this->child), 'up', 'ha');

    expect($result->completion->status)->toBe(CompletionStatus::Auto);
});

it('vergibt Börsen-Aufgaben nur einmal', function () {
    $sibling = joinFamily($this->family);
    $task = makeTask($this->parent, ['scope' => 'family', 'assignment_mode' => 'pool', 'star_reward' => 5]);

    app(TaskService::class)->claim($task, $this->child);

    expect(fn () => app(TaskService::class)->claim($task, $sibling))->toThrow(GameException::class);
    expect($task->assignments()->pluck('user_id')->all())->toBe([$this->child->id]);
});

it('löst Familienbelohnungen mit Sternen ein und erstattet bei Ablehnung', function () {
    $this->child->stats()->update(['stars' => 10]);
    $item = $this->family->rewardItems()->create(['title' => '30 min Konsole', 'star_cost' => 6]);
    $service = app(RewardService::class);

    $redemption = $service->redeem($item, $this->child);
    expect($this->child->stats()->first()->stars)->toBe(4)->and($redemption->status)->toBe('pending');

    $service->review($redemption, $this->parent, false);
    expect($this->child->stats()->first()->stars)->toBe(10);
});

it('verhindert Einlösen ohne genug Sterne und über dem Wochenlimit', function () {
    $this->child->stats()->update(['stars' => 10]);
    $item = $this->family->rewardItems()->create(['title' => 'Eis', 'star_cost' => 2, 'limit_per_week' => 1]);
    $service = app(RewardService::class);

    $service->redeem($item, $this->child);

    expect(fn () => $service->redeem($item, $this->child))->toThrow(GameException::class);
});
