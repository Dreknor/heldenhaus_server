<?php

use App\Domain\Gameplay\GameException;
use App\Domain\Gameplay\RunDayCron;
use App\Domain\Gameplay\ScoreTask;
use App\Domain\Rewards\RewardService;
use App\Domain\Tasks\ReviewCompletion;
use App\Mail\WeeklyReportMail;
use App\Models\AllowanceEntry;
use App\Models\FeedEntry;
use App\Models\FeedReaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-19 10:00', 'Europe/Berlin')); // Montag
    [$this->family, $this->parent, $this->child] = makeFamily();
    $this->parent->update(['name' => 'Mama']); // feste Sortierung: Ben, Lea, Mama
    $this->sibling = joinFamily($this->family, attributes: ['name' => 'Ben', 'birth_year' => now()->year - 14, 'timezone' => 'Europe/Berlin', 'day_start' => 0]);
    $this->score = app(ScoreTask::class);
});

function rotationTask($test, string $interval = 'daily')
{
    return makeTask($test->parent, [
        'scope' => 'family', 'type' => 'daily', 'title' => 'Spülmaschine', 'assignment_mode' => 'rotation',
        'assignees' => [$test->child->id, $test->sibling->id], 'rotation_interval' => $interval, 'approval_mode' => 'none',
    ]);
}

it('Ämterplan: täglich abwechselnd, nur wer dran ist, kann abhaken', function () {
    $task = rotationTask($this);

    expect($task->dutyUserId(now()))->toBe($this->child->id)
        ->and($task->dutyUserId(now()->addDay()))->toBe($this->sibling->id)
        ->and($task->dutyUserId(now()->addDays(2)))->toBe($this->child->id);

    expect(fn () => ($this->score)(assignmentOf($task, $this->sibling), 'up'))->toThrow(GameException::class, 'Lea');
    ($this->score)(assignmentOf($task, $this->child), 'up');
    expect(assignmentOf($task, $this->child)->completed)->toBeTrue();
});

it('Ämterplan: wöchentlicher Wechsel', function () {
    $task = rotationTask($this, 'weekly');

    expect($task->dutyUserId(now()->addDays(6)))->toBe($this->child->id)
        ->and($task->dutyUserId(now()->addDays(7)))->toBe($this->sibling->id);
});

it('Ämterplan: verpasst nur, wer dran war', function () {
    $cron = app(RunDayCron::class);
    $cron($this->child);
    $cron($this->sibling);
    rotationTask($this);

    $this->travelTo(CarbonImmutable::parse('2026-10-20 08:00', 'Europe/Berlin'));
    $cron($this->child->refresh());
    $cron($this->sibling->refresh());

    expect($this->child->stats()->first()->hp)->toBeLessThan(50.0)
        ->and($this->sibling->stats()->first()->hp)->toBe(50.0);
});

it('Ämterplan nimmt nur Mitglieder der eigenen Familie auf', function () {
    [, , $foreign] = makeFamily();
    $task = makeTask($this->parent, [
        'scope' => 'family', 'type' => 'daily', 'assignment_mode' => 'rotation', 'approval_mode' => 'none',
        'assignees' => [$foreign->id, $this->child->id],
    ]);

    expect($task->refresh()->rotation['members'])->toBe([$this->child->id]);
});

it('Teamaufgabe: Bonus für alle, sobald alle ihren Teil erledigt haben', function () {
    $task = makeTask($this->parent, [
        'scope' => 'family', 'type' => 'todo', 'title' => 'Garten', 'assignment_mode' => 'team', 'approval_mode' => 'none',
        'assignees' => [$this->child->id, $this->sibling->id], 'team_bonus_gold' => 5,
    ]);

    ($this->score)(assignmentOf($task, $this->child), 'up');
    expect($this->child->stats()->first()->gold)->toBe(1.0);

    ($this->score)(assignmentOf($task, $this->sibling), 'up');
    expect($this->child->stats()->first()->gold)->toBe(6.0)
        ->and($this->sibling->stats()->first()->gold)->toBe(6.0)
        ->and(FeedEntry::where('type', 'team_done')->exists())->toBeTrue();
});

it('Feed: Familienaufgaben ja, persönliche Aufgaben nie', function () {
    ($this->score)(assignmentOf(makeTask($this->child, ['title' => 'Tagebuch', 'is_private' => true]), $this->child), 'up');
    $family = makeTask($this->parent, ['scope' => 'family', 'type' => 'todo', 'title' => 'Müll', 'assignment_mode' => 'assigned', 'assignees' => [$this->child->id], 'approval_mode' => 'none']);
    ($this->score)(assignmentOf($family, $this->child), 'up');

    expect(FeedEntry::pluck('text')->all())->toBe(['Lea hat „Müll“ erledigt']);
});

it('Feed: Freigabepflichtige Aufgaben erscheinen erst nach Bestätigung', function () {
    $task = makeTask($this->parent, ['scope' => 'family', 'type' => 'todo', 'title' => 'Zimmer', 'assignment_mode' => 'assigned', 'assignees' => [$this->child->id], 'approval_mode' => 'required']);
    $result = ($this->score)(assignmentOf($task, $this->child), 'up');
    expect(FeedEntry::count())->toBe(0);

    app(ReviewCompletion::class)->approve($result->completion, $this->parent);
    expect(FeedEntry::count())->toBe(1);
});

it('Feed: Reaktionen umschalten, nur bekannte Emojis, nur eigene Familie', function () {
    $entry = FeedEntry::create(['family_id' => $this->family->id, 'type' => 'level_up', 'text' => 'Lea hat Level 2 erreicht!']);

    $this->actingAs($this->parent)->post("/feed/{$entry->id}/react", ['emoji' => '🎉']);
    expect(FeedReaction::count())->toBe(1);
    $this->post("/feed/{$entry->id}/react", ['emoji' => '🎉']);
    expect(FeedReaction::count())->toBe(0);

    $this->post("/feed/{$entry->id}/react", ['emoji' => '💩'])->assertSessionHasErrors('game');
    [, $foreignParent] = makeFamily();
    $this->actingAs($foreignParent)->post("/feed/{$entry->id}/react", ['emoji' => '👍'])->assertSessionHasErrors('game');
});

it('Level-ups landen im Feed', function () {
    $this->child->stats()->update(['xp' => 149]);
    ($this->score)(assignmentOf(makeTask($this->child), $this->child), 'up');

    expect(FeedEntry::where('type', 'level_up')->value('text'))->toBe('Lea hat Level 2 erreicht!');
});

it('Taschengeld: Eltern buchen, Kinder sehen nur ihr Konto, Belohnungen buchen automatisch', function () {
    $this->actingAs($this->parent)->post('/allowance', ['user_id' => $this->child->id, 'amount' => 5, 'description' => 'Wochengeld'])->assertSessionHasNoErrors();
    $this->actingAs($this->child)->post('/allowance', ['user_id' => $this->child->id, 'amount' => 100, 'description' => 'Hack'])->assertForbidden();

    $item = $this->family->rewardItems()->create(['title' => '1 € extra', 'star_cost' => 2, 'allowance_cents' => 100, 'requires_approval' => false]);
    $this->child->stats()->update(['stars' => 5]);
    app(RewardService::class)->redeem($item, $this->child);

    expect(AllowanceEntry::where('user_id', $this->child->id)->sum('amount_cents'))->toEqual(600);

    $this->get('/allowance')->assertInertia(fn ($page) => $page->has('accounts', 1)->where('accounts.0.balance_cents', 600));
});

it('Wochenbericht: Zahlen, Fairness-Anteile und E-Mail an Eltern', function () {
    Mail::fake();
    $task = makeTask($this->parent, ['scope' => 'family', 'type' => 'todo', 'assignment_mode' => 'assigned', 'assignees' => [$this->child->id, $this->sibling->id], 'approval_mode' => 'none', 'star_reward' => 2]);
    ($this->score)(assignmentOf($task, $this->child), 'up');
    ($this->score)(assignmentOf($task, $this->sibling), 'up');
    ($this->score)(assignmentOf(makeTask($this->child), $this->child), 'up');

    $this->actingAs($this->parent)->get('/family/report')->assertInertia(fn ($page) => $page
        ->component('family/Report')
        ->where('report.members.1.name', 'Lea')
        ->where('report.members.1.tasks_done', 2)
        ->where('report.members.1.family_share', 50)
        ->where('report.members.1.stars', 2));
    $this->actingAs($this->child)->get('/family/report')->assertForbidden();

    $this->artisan('heldenhaus:weekly-reports')->assertSuccessful();
    Mail::assertQueued(WeeklyReportMail::class, fn ($mail) => $mail->hasTo($this->parent->email));
});

it('API: Feed, Taschengeld und Wochenbericht', function () {
    FeedEntry::create(['family_id' => $this->family->id, 'type' => 'level_up', 'text' => 'Hallo']);
    Sanctum::actingAs($this->parent, ['app']);

    $this->getJson('/api/v1/feed')->assertOk()->assertJsonPath('entries.0.text', 'Hallo');
    $this->postJson('/api/v1/allowance', ['user_id' => $this->child->id, 'amount' => -2.5, 'description' => 'Eis'])->assertCreated();
    $this->getJson('/api/v1/allowance')->assertJsonPath('accounts.1.name', 'Lea')->assertJsonPath('accounts.1.balance_cents', -250);
    $this->getJson('/api/v1/family/report')->assertOk()->assertJsonStructure(['members', 'quests', 'pending_approvals']);
});
