<?php

use App\Domain\Insights\Hints;
use App\Domain\Insights\NightlyInsights;
use App\Domain\Insights\SurveyService;
use App\Models\FamilyMetric;
use App\Models\Survey;
use App\Models\Task;
use App\Models\TaskCompletion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;

function completeOn(User $user, Task $task, CarbonImmutable $day, int $times, array $extra = []): void
{
    for ($i = 0; $i < $times; $i++) {
        $c = new TaskCompletion(['task_id' => $task->id, 'user_id' => $user->id, 'direction' => 'up', 'delta' => 1, 'status' => 'none', ...$extra]);
        $c->created_at = $day->setTimezone('Europe/Berlin')->setTime(17, $i);
        $c->save();
    }
}

function mood(User $user, int $value, array $reasons, string $date): void
{
    Survey::create(['user_id' => $user->id, 'family_id' => $user->family_id, 'kind' => 'mood', 'date' => $date, 'answers' => ['mood' => $value, 'reasons' => $reasons]]);
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-19 10:00', 'Europe/Berlin'));
    [$this->family, $this->parent, $this->child] = makeFamily();
    $this->child->forceFill(['created_at' => now()->subDays(60)])->save();
    $this->hints = app(Hints::class);
});

it('gibt einen Hinweis, wenn die Aktivität eines Kindes zwei Wochen um mehr als 30 % sinkt', function () {
    $task = makeTask($this->child);
    $today = CarbonImmutable::parse('2026-11-19', 'Europe/Berlin');
    for ($d = 28; $d >= 15; $d--) {
        completeOn($this->child, $task, $today->subDays($d), 3);   // 12 Punkte pro Tag
    }
    for ($d = 14; $d >= 1; $d--) {
        completeOn($this->child, $task, $today->subDays($d), 2);   // 8 Punkte: −33 %
    }
    $total = Task::count();

    $hints = collect($this->hints->for($this->family));
    expect($hints->pluck('type')->all())->toBe(['activity_drop'])
        ->and($hints->first()['suggestions'])->toHaveCount(3)
        ->and(Task::count())->toBe($total); // nur ein Hinweis, nichts wird geändert

    // Nur eine Woche weniger: kein Hinweis
    for ($d = 14; $d >= 8; $d--) {
        completeOn($this->child, $task, $today->subDays($d), 1);   // Woche 2 wieder bei 12
    }
    expect($this->hints->for($this->family))->toBe([]);
});

it('gibt einen Hinweis bei zweimal Stimmung ≤ 2 und nennt die Gründe', function () {
    mood($this->child, 2, ['too_hard'], '2026-10-20');
    mood($this->child, 3, [], '2026-11-17');
    expect($this->hints->for($this->family))->toBe([]);

    mood($this->child, 1, ['too_many'], '2026-11-18');
    expect($this->hints->for($this->family))->toBe([]); // 3 und 1 → nicht zweimal

    mood($this->child, 2, ['too_hard'], '2026-11-19');
    $hint = $this->hints->for($this->family)[0];
    expect($hint['type'])->toBe('low_mood')
        ->and($hint['text'])->toContain('Zu viele Aufgaben')->toContain('Zu schwer')
        ->and(implode(' ', $hint['suggestions']))->toContain('leicht');
});

it('weist auf mehr als 12 Dailies bei Kindern unter 13 hin', function () {
    foreach (range(1, 13) as $i) {
        makeTask($this->child, ['type' => 'daily', 'title' => "Daily {$i}"]);
    }

    expect(collect($this->hints->for($this->family))->pluck('type')->all())->toBe(['too_many_dailies']);
});

it('regelt die Fälligkeit der Umfragen und speichert Antworten', function () {
    $surveys = app(SurveyService::class);
    expect($surveys->due($this->parent)['kind'])->toBe('baseline')
        ->and($surveys->due($this->child)['kind'])->toBe('mood'); // seit 60 Tagen dabei

    $this->actingAs($this->parent)->post('/surveys', ['kind' => 'baseline', 'answers' => ['self_started' => 2, 'conflicts' => 4, 'independence' => 2]])
        ->assertSessionHasNoErrors();
    $this->post('/surveys', ['kind' => 'baseline', 'answers' => ['self_started' => 2]])->assertSessionHasErrors('game');
    expect($surveys->due($this->parent))->toBeNull(); // Elternteil ist neu, Stimmung erst nach 4 Wochen

    Sanctum::actingAs($this->child, ['app']);
    $this->postJson('/api/v1/surveys', ['kind' => 'baseline', 'answers' => ['self_started' => 3, 'conflicts' => 3, 'independence' => 3]])->assertUnprocessable();
    $this->postJson('/api/v1/surveys', ['kind' => 'mood', 'answers' => ['mood' => 2, 'reasons' => ['boring']]])->assertCreated();
    $this->getJson('/api/v1/surveys/due')->assertJsonPath('survey', null);

    // Nach 8 Wochen dieselben Fragen wieder, danach Vorher-Nachher
    $this->travel(56)->days();
    expect($surveys->due($this->parent->refresh())['kind'])->toBe('baseline');
    $surveys->submit($this->parent, 'baseline', ['self_started' => 4, 'conflicts' => 2, 'independence' => 3]);

    Sanctum::actingAs($this->parent, ['app']);
    $this->getJson('/api/v1/family/metrics/compare')->assertOk()
        ->assertJsonPath('baseline.rounds', 2)
        ->assertJsonPath('baseline.questions.0.before', 2)
        ->assertJsonPath('baseline.questions.0.after', 4)
        ->assertJsonPath('baseline.questions.1.after', 2);
});

it('fasst Kennzahlen nächtlich in der Zeitzone der Familie zusammen und zeigt sie nur Eltern', function () {
    $family = makeTask($this->parent, ['scope' => 'family', 'type' => 'todo', 'title' => 'Müll rausbringen', 'assignees' => [$this->child->id], 'due_date' => '2026-11-18']);
    completeOn($this->child, $family, CarbonImmutable::parse('2026-11-17', 'Europe/Berlin'), 1, ['stars' => 3, 'status' => 'approved']);

    $nightly = app(NightlyInsights::class);
    expect($nightly->family($this->family, CarbonImmutable::parse('2026-11-19 01:30', 'Europe/Berlin'))['metrics'])->toBeFalse();
    expect($nightly->family($this->family, CarbonImmutable::parse('2026-11-19 02:10', 'Europe/Berlin'))['metrics'])->toBeTrue()
        ->and(FamilyMetric::where('family_id', $this->family->id)->count())->toBe(14);

    Sanctum::actingAs($this->parent, ['app']);
    $child = collect($this->getJson('/api/v1/family/metrics')->assertOk()->json('summary.members'))->firstWhere('id', $this->child->id);
    expect($child['family_tasks'])->toMatchArray(['done' => 1, 'with_due' => 1, 'on_time' => 1])
        ->and($child['stars']['earned'])->toBe(3)
        ->and($child['active_days_per_week'])->toBe(0.5);

    $this->actingAs($this->parent)->get('/family/insights')->assertOk()->assertInertia(fn ($page) => $page->component('family/Insights')->has('hints'));

    Sanctum::actingAs($this->child, ['app']);
    $this->getJson('/api/v1/family/metrics')->assertForbidden();
    $this->actingAs($this->child)->get('/family/insights')->assertForbidden();
});

it('zeigt dem Admin nur zusammengefasste Zahlen ohne Titel und Namen', function () {
    $task = makeTask($this->child, ['title' => 'Hamster füttern']);
    completeOn($this->child, $task, CarbonImmutable::parse('2026-11-18', 'Europe/Berlin'), 3);
    mood($this->child, 4, ['other'], '2026-11-18');
    app(NightlyInsights::class)->family($this->family, CarbonImmutable::now());

    $admin = User::factory()->create(['name' => 'Admina']);
    $admin->forceFill(['is_admin' => true])->save();

    $this->actingAs($this->parent)->get('/admin/insights')->assertForbidden();
    $response = $this->actingAs($admin)->get('/admin/insights')->assertOk();
    $response->assertInertia(fn ($page) => $page->component('admin/Insights')->where('summary.families', 1)->where('summary.mood.answers', 1));

    $summary = json_encode($response->viewData('page')['props']['summary'], JSON_UNESCAPED_UNICODE);
    foreach (['Familie Test', 'Lea', $this->parent->name, 'Hamster füttern', 'Testaufgabe', $this->family->login_code] as $secret) {
        expect($summary)->not->toContain($secret);
    }
    expect($summary)->not->toContain('"user_id"')->not->toContain('"name"');
});
