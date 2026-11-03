<?php

use App\Domain\Gameplay\Formulas;
use App\Domain\Gameplay\GameException;
use App\Domain\Gameplay\ScoreTask;
use App\Enums\CompletionStatus;
use App\Http\Presenters\GamePresenter;
use App\Models\TaskCompletion;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->score = app(ScoreTask::class);
});

it('gibt XP und Gold beim Erledigen eines To-dos', function () {
    $task = makeTask($this->user, ['type' => 'todo', 'difficulty' => 1.5]);

    $result = ($this->score)(assignmentOf($task, $this->user), 'up');

    expect($result->completion->xp)->toBe(9)
        ->and($result->stats->gold)->toBe(1.5)
        ->and(assignmentOf($task, $this->user)->completed)->toBeTrue()
        ->and(assignmentOf($task, $this->user)->value)->toBe(1.0);
});

it('lässt ein To-do nicht zweimal abhaken', function () {
    $task = makeTask($this->user);
    ($this->score)(assignmentOf($task, $this->user), 'up');

    ($this->score)(assignmentOf($task, $this->user), 'up');
})->throws(GameException::class);

it('nimmt einen Abschluss vollständig zurück', function () {
    $task = makeTask($this->user, ['type' => 'daily']);
    ($this->score)(assignmentOf($task, $this->user), 'up');

    ($this->score)(assignmentOf($task, $this->user), 'down');

    $assignment = assignmentOf($task, $this->user);
    $stats = $this->user->stats()->first();
    expect($assignment->completed)->toBeFalse()
        ->and($assignment->streak)->toBe(0)
        ->and($assignment->value)->toBe(0.0)
        ->and($stats->xp)->toBe(0)
        ->and(goldWithoutAchievements($this->user))->toBe(0.0) // erreichte Erfolge bleiben
        ->and(TaskCompletion::first()->status)->toBe(CompletionStatus::Undone);
});

it('erhöht die Streak bei Dailies und gibt Streak-Bonus', function () {
    $task = makeTask($this->user, ['type' => 'daily']);
    assignmentOf($task, $this->user)->update(['streak' => 50]);

    $result = ($this->score)(assignmentOf($task, $this->user), 'up');

    expect(assignmentOf($task, $this->user)->streak)->toBe(51)
        ->and($result->completion->gold)->toBe(1.5);
});

it('macht bei negativen Gewohnheiten gedämpften Schaden', function () {
    $child = User::factory()->create(['birth_year' => now()->year - 10]);
    $task = makeTask($child, ['type' => 'habit', 'up' => false, 'down' => true]);

    $result = ($this->score)(assignmentOf($task, $child), 'down');

    expect($result->stats->hp)->toBe(49.0)
        ->and(assignmentOf($task, $child)->value)->toBe(-1.0)
        ->and(assignmentOf($task, $child)->counter_down)->toBe(1);
});

it('zählt Gewohnheiten mehrfach und mit abnehmendem Ertrag', function () {
    $task = makeTask($this->user, ['type' => 'habit']);

    $first = ($this->score)(assignmentOf($task, $this->user), 'up');
    $second = ($this->score)(assignmentOf($task, $this->user), 'up');

    expect($second->completion->delta)->toBeLessThan($first->completion->delta)
        ->and(assignmentOf($task, $this->user)->counter_up)->toBe(2);
});

it('ist mit client_uuid idempotent (Offline-Abgleich)', function () {
    $task = makeTask($this->user, ['type' => 'habit']);
    $uuid = (string) Str::uuid();

    ($this->score)(assignmentOf($task, $this->user), 'up', 'app', $uuid);
    $replay = ($this->score)(assignmentOf($task, $this->user), 'up', 'app', $uuid);

    expect($replay->replayed)->toBeTrue()
        ->and(TaskCompletion::count())->toBe(1)
        ->and(assignmentOf($task, $this->user)->counter_up)->toBe(1);
});

it('steigt im Level auf', function () {
    $this->user->stats()->update(['xp' => 148]);
    $task = makeTask($this->user, ['difficulty' => 2]);

    $result = ($this->score)(assignmentOf($task, $this->user), 'up');

    expect($result->levelUps)->toBe(1)
        ->and($result->stats->level)->toBe(2)
        ->and($result->stats->unspent_points)->toBe(1);
});

it('gibt bei Gewohnheiten für jede Wiederholung am Tag weniger und zählt über dem Limit nur noch', function () {
    $task = makeTask($this->user, ['type' => 'habit', 'difficulty' => 1, 'daily_limit' => 3]);

    $first = ($this->score)(assignmentOf($task, $this->user), 'up');
    $second = ($this->score)(assignmentOf($task, $this->user), 'up');
    $third = ($this->score)(assignmentOf($task, $this->user), 'up');
    $valueBefore = assignmentOf($task, $this->user)->value;
    $over = ($this->score)(assignmentOf($task, $this->user), 'up');

    expect($first->habit)->toMatchArray(['today' => 1, 'limit' => 3, 'factor' => 1.0, 'limited' => false])
        ->and($second->habit['factor'])->toBe(0.75)
        ->and($second->completion->xp)->toBeLessThan($first->completion->xp)
        ->and($third->habit['factor'])->toBe(0.5625)
        ->and($over->habit['limited'])->toBeTrue()
        ->and($over->completion->xp)->toBe(0)
        ->and((float) $over->completion->gold)->toBe(0.0)
        ->and($over->completion->effects)->toBe(['habit_daily_limit'])
        ->and(assignmentOf($task, $this->user)->value)->toBe($valueBefore)
        ->and(assignmentOf($task, $this->user)->counter_up)->toBe(4)
        ->and($over->toArray()['habit']['color']['key'])->toBe('good');

    // Am nächsten Tag gibt es wieder die volle Belohnung
    $this->travel(1)->days();
    expect(($this->score)(assignmentOf($task, $this->user), 'up')->habit)->toMatchArray(['today' => 1, 'factor' => 1.0]);
});

it('zeigt Farbe und heutige Zählung in der API', function () {
    $child = User::factory()->create();
    $task = makeTask($child, ['type' => 'habit', 'difficulty' => 1]);
    ($this->score)(assignmentOf($task, $child), 'up');

    expect(GamePresenter::assignment(assignmentOf($task, $child)->load('task')))
        ->toMatchArray(['daily_limit' => 8, 'color' => ['key' => 'good', 'hex' => '#22c55e'], 'habit_today' => ['up' => 1, 'down' => 0, 'limit' => 8, 'next_factor' => 0.75]]);
});

it('ordnet Werte den Farbstufen wie im Original zu', function () {
    expect(collect([-25, -15, -5, 0, 3, 7, 15])->map(fn ($v) => Formulas::color($v)['key'])->all())
        ->toBe(['worst', 'worse', 'bad', 'neutral', 'good', 'better', 'best']);
});
