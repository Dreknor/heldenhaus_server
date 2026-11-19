<?php

use App\Domain\Council\ParentHelp;
use App\Domain\Gameplay\GameException;
use App\Enums\CompletionStatus;
use App\Models\Task;
use App\Models\TaskCompletion;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    [$this->family, $this->parent, $this->child] = makeFamily();
    $this->help = app(ParentHelp::class);
});

it('GET task-templates filtert nach Alter und Bereich', function () {
    Sanctum::actingAs($this->parent, ['app']);
    $all = $this->getJson('/api/v1/task-templates')->assertOk()->json('templates');
    $kitchen = $this->getJson('/api/v1/task-templates?age=9-10&area=kitchen')->assertOk()->json('templates');
    $forLea = $this->getJson("/api/v1/task-templates?member={$this->child->id}")->assertOk();

    expect(count($all))->toBe(86)
        ->and(collect($kitchen)->pluck('area')->unique()->all())->toBe(['kitchen'])
        ->and(collect($kitchen)->every(fn ($t) => in_array('9-10', $t['ages'], true)))->toBeTrue()
        ->and($forLea->json('age'))->toBe('9-10');
});

it('Startassistent: nach dem Anlegen eines Kindes 3–5 Vorlagen wählen, nicht mehr', function () {
    $this->actingAs($this->parent)->post('/family/children', ['name' => 'Ben', 'birth_year' => now()->year - 10, 'pin' => '4321'])
        ->assertRedirect();
    $ben = User::where('name', 'Ben')->first();
    $this->get("/family/members/{$ben->id}/start")->assertOk()
        ->assertInertia(fn ($p) => $p->component('family/StartWizard')->where('member.age_band', '9-10')->where('max', 5));

    $this->post("/family/members/{$ben->id}/starter-tasks", ['templates' => ['make_bed', 'feed_pet']])->assertSessionHasErrors('templates');
    $this->post("/family/members/{$ben->id}/starter-tasks", ['templates' => ['make_bed', 'feed_pet', 'set_table', 'homework', 'brush_teeth_evening', 'shower']])
        ->assertSessionHasErrors('templates');
    $this->post("/family/members/{$ben->id}/starter-tasks", ['templates' => ['make_bed', 'feed_pet', 'set_table']])->assertSessionHasNoErrors();

    $tasks = Task::whereHas('assignments', fn ($q) => $q->where('user_id', $ben->id))->get();
    expect($tasks)->toHaveCount(3)
        // Mit Sternen: Familienaufgabe mit Freigabe; ohne Sterne: persönliche Aufgabe des Kindes
        ->and($tasks->where('star_reward', '>', 0))->toHaveCount(2)
        ->and($tasks->where('star_reward', '>', 0)->every(fn ($t) => $t->isFamilyTask()))->toBeTrue()
        ->and($tasks->where('star_reward', '>', 0)->every(fn ($t) => $t->approval_mode->value === 'required'))->toBeTrue()
        ->and($tasks->firstWhere('scope', 'personal')->owner_id)->toBe($ben->id)
        ->and(ParentHelp::newTasksThisWeek($ben->refresh()))->toBe(0);

    expect(fn () => $this->help->starterTasks($this->parent, $ben, ['make_bed', 'feed_pet', 'set_table']))->toThrow(GameException::class);
});

it('Hinweis, wenn mehr als eine neue Aufgabe pro Woche dazukommt', function () {
    $this->help->starterTasks($this->parent, $this->child, ['make_bed', 'feed_pet', 'set_table']);
    $this->travel(1)->days();
    $data = ['scope' => 'family', 'type' => 'todo', 'category' => 'household', 'difficulty' => 1, 'assignment_mode' => 'assigned',
        'assignees' => [$this->child->id], 'approval_mode' => 'none'];

    $this->actingAs($this->parent)->post('/tasks', $data + ['title' => 'Schuhe putzen'])->assertSessionHas('success', 'Aufgabe angelegt.');
    $this->post('/tasks', $data + ['title' => 'Keller fegen'])
        ->assertSessionHas('success', fn ($m) => str_contains($m, 'Lea hat diese Woche schon 2 neue Aufgaben'));

    expect(ParentHelp::loadHints($this->family->id))->toHaveCount(1);
    $this->travel(8)->days();
    expect(ParentHelp::loadHints($this->family->id))->toBe([]);
});

it('Preis-Hilfe aus dem Sterne-Schnitt der letzten 14 Tage und Sterne-Polster', function () {
    $task = makeTask($this->parent, ['scope' => 'family', 'type' => 'todo', 'category' => 'household', 'assignment_mode' => 'assigned',
        'assignees' => [$this->child->id], 'approval_mode' => 'required', 'star_reward' => 2]);
    // 28 Sterne in 14 Tagen → 2 pro Tag
    foreach (range(1, 14) as $i) {
        TaskCompletion::create(['task_id' => $task->id, 'user_id' => $this->child->id, 'direction' => 'up', 'status' => CompletionStatus::Approved, 'stars' => 2]);
    }
    // Älter als 14 Tage zählt nicht
    $old = TaskCompletion::create(['task_id' => $task->id, 'user_id' => $this->child->id, 'direction' => 'up', 'status' => CompletionStatus::Approved, 'stars' => 50]);
    $old->forceFill(['created_at' => now()->subDays(20)])->save();

    expect(ParentHelp::duration(6, 2.0))->toBe('etwa 3 Tage Familienaufgaben')
        ->and(ParentHelp::duration(28, 2.0))->toBe('etwa 2 Wochen Familienaufgaben')
        ->and(ParentHelp::duration(5, 0))->toContain('noch keine Sterne');

    Sanctum::actingAs($this->parent, ['app']);
    $this->getJson('/api/v1/shop/price-help?stars=6')->assertOk()
        ->assertJsonPath('children.0.name', 'Lea')->assertJsonPath('children.0.stars_per_day', 2)
        ->assertJsonPath('children.0.text', 'etwa 3 Tage Familienaufgaben')
        ->assertJsonPath('cushions', []);

    $this->child->stats()->update(['stars' => 60]);
    expect(ParentHelp::starCushions($this->family->id))->toHaveCount(1);

    Sanctum::actingAs($this->child, ['app']);
    $this->getJson('/api/v1/shop/price-help?stars=6')->assertForbidden();
});

it('Eltern-Anleitung: 6 Karten, beim ersten Login einmal angeboten', function () {
    expect(ParentHelp::GUIDE)->toHaveCount(6);
    $this->actingAs($this->parent)->get('/dashboard')->assertInertia(fn ($p) => $p->where('councilHints.0.key', 'guide'));

    $this->get('/help/parents')->assertOk()->assertInertia(fn ($p) => $p->component('help/ParentGuide')->has('cards', 6));
    expect($this->parent->refresh()->parent_guide_seen_at)->not->toBeNull();
    $this->get('/dashboard')->assertInertia(fn ($p) => $p->where('councilHints', fn ($h) => collect($h)->doesntContain('key', 'guide')));

    // Kinder bekommen das Angebot nicht
    $this->actingAs($this->child)->get('/dashboard')->assertInertia(fn ($p) => $p->where('councilHints', fn ($h) => collect($h)->doesntContain('key', 'guide')));
});
