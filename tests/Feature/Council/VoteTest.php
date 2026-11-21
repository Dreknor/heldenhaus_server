<?php

use App\Domain\Council\VoteService;
use App\Domain\Gameplay\GameException;
use App\Domain\Gameplay\ScoreTask;
use App\Domain\Quests\QuestService;
use App\Enums\FamilyRole;
use App\Models\Quest;
use App\Models\Vote;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-19 10:00', 'Europe/Berlin'));
    [$this->family, $this->parent, $this->child] = makeFamily();
    $this->votes = app(VoteService::class);
});

it('bietet drei Familien-Quests der Quest-Tafel zur Wahl', function () {
    $vote = $this->votes->open($this->parent);
    $offers = collect(app(QuestService::class)->offers($this->parent, 'family'))->pluck('key')->take(3)->all();

    expect($vote->optionKeys())->toBe($offers)->toHaveCount(3);
    expect(fn () => $this->votes->open($this->child))->toThrow(GameException::class);
});

it('eine Stimme pro Mitglied, Gäste stimmen nicht ab', function () {
    $sibling = joinFamily($this->family, attributes: ['birth_year' => now()->year - 11]);
    $guest = joinFamily($this->family, FamilyRole::Guest);
    $vote = $this->votes->open($this->parent);
    [$a] = $vote->optionKeys();

    Sanctum::actingAs($this->child, ['app']);
    $this->postJson("/api/v1/votes/{$vote->id}", ['option' => $a])->assertOk()->assertJsonPath('my_vote', $a)->assertJsonPath('votes_cast', 1);
    $this->postJson("/api/v1/votes/{$vote->id}", ['option' => $a])->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'eine Stimme'));
    // Kinder können nicht für andere abstimmen
    $this->postJson("/api/v1/votes/{$vote->id}", ['option' => $a, 'member_id' => $sibling->id])->assertStatus(422);

    expect(fn () => $this->votes->cast($guest, $vote, $a))->toThrow(GameException::class);
    expect(count($vote->refresh()->ballots))->toBe(1)->and($vote->status)->toBe('open');
});

it('alle haben abgestimmt: die Mehrheit gewinnt und die Quest startet über die Quest-Tafel', function () {
    $vote = $this->votes->open($this->parent);
    [$a, $b] = $vote->optionKeys();
    $sibling = joinFamily($this->family, attributes: ['birth_year' => now()->year - 11]);

    $this->votes->cast($this->child, $vote, $a);
    // Am Familiengerät trägt ein Elternteil die Stimme eines Kindes ein
    $this->votes->cast($this->parent, $vote, $a, $sibling);
    $vote = $this->votes->cast($this->parent, $vote, $b);

    expect($vote->status)->toBe('started')->and($vote->winner)->toBe($a);
    $quest = Quest::find($vote->quest_id);
    expect($quest->quest_key)->toBe($a)->and($quest->scope)->toBe('family')->and($quest->status)->toBe('active');
});

it('Gleichstand entscheiden die Eltern', function () {
    $vote = $this->votes->open($this->parent);
    [$a, $b, $c] = $vote->optionKeys();
    $this->votes->cast($this->child, $vote, $a);
    $vote = $this->votes->cast($this->parent, $vote, $b);

    expect($vote->status)->toBe('tie')->and(VoteService::leaders($vote))->toEqualCanonicalizing([$a, $b]);

    Sanctum::actingAs($this->child, ['app']);
    $this->postJson("/api/v1/votes/{$vote->id}", ['decide' => $a])->assertStatus(422);

    Sanctum::actingAs($this->parent, ['app']);
    $this->postJson("/api/v1/votes/{$vote->id}", ['decide' => $c])->assertStatus(422);
    $this->postJson("/api/v1/votes/{$vote->id}", ['decide' => $b])->assertOk()->assertJsonPath('status', 'started')->assertJsonPath('winner', $b);
});

it('läuft noch eine Familien-Quest, startet die gewählte nach ihrem Ende', function () {
    $running = app(QuestService::class)->start($this->parent, 'dust_monster', 'family');
    $vote = $this->votes->open($this->parent);
    $winner = collect($vote->optionKeys())->first();
    $this->votes->cast($this->child, $vote, $winner);
    $vote = $this->votes->cast($this->parent, $vote, $winner);

    expect($vote->status)->toBe('decided');

    // Quest geschafft → Listener startet die gewählte Quest
    $running->update(['progress' => $running->goal - 1]);
    $task = makeTask($this->child, ['difficulty' => 2]);
    app(ScoreTask::class)(assignmentOf($task, $this->child), 'up');

    expect($running->refresh()->status)->toBe('completed')
        ->and($vote->refresh()->status)->toBe('started')
        ->and(Quest::find($vote->quest_id)->quest_key)->toBe($winner);
});

it('abgelaufene Abstimmungen schließt der Scheduler', function () {
    $vote = $this->votes->open($this->parent);
    $this->votes->cast($this->child, $vote, $vote->optionKeys()[1]);
    $this->travel(4)->days();

    $this->artisan('heldenhaus:council-tick')->assertSuccessful();

    expect(Vote::find($vote->id)->status)->toBe('started');
});
