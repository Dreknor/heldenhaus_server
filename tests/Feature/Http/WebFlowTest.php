<?php

use App\Enums\CompletionStatus;
use App\Models\HaAuditEntry;
use App\Models\TaskCompletion;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    [$this->family, $this->parent, $this->child] = makeFamily();
});

test('gäste werden zum login umgeleitet', function () {
    $this->get('/dashboard')->assertRedirect('/login');
});

test('ohne familie geht es zur einrichtung', function () {
    $this->actingAs(User::factory()->create())->get('/dashboard')->assertRedirect('/onboarding');
});

test('das heldenpanel zeigt die eigenen aufgaben', function () {
    makeTask($this->child, ['type' => 'daily', 'title' => 'Zähne putzen']);
    makeTask($this->parent, ['scope' => 'family', 'type' => 'todo', 'title' => 'Auto saugen', 'assignment_mode' => 'pool', 'star_reward' => 5]);

    $this->actingAs($this->child)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->has('dailies', 1)
            ->where('dailies.0.title', 'Zähne putzen')
            ->has('pool', 1)
            ->where('auth.stats.level', 1));
});

test('das helden-board fasst dungeon, garten und home assistant zusammen', function () {
    HaAuditEntry::create(['family_id' => $this->family->id, 'direction' => 'in', 'action' => 'POST events', 'status' => 200]);

    $this->actingAs($this->child)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('hub.dungeon.active', null)
            ->has('hub.dungeon.unlockLevel')
            ->where('hub.garden.planted', 0)
            ->where('hub.homeAssistant.connected', true)
            ->whereType('hub.homeAssistant.lastEventAt', 'string'));
});

test('kinder können eigene aufgaben anlegen und abhaken', function () {
    $this->actingAs($this->child)->post('/tasks', [
        'type' => 'todo', 'title' => 'Mathe lernen', 'category' => 'school', 'difficulty' => 1.5,
    ])->assertSessionHasNoErrors();

    $task = $this->child->assignments()->first();
    $this->post("/assignments/{$task->id}/score/up")->assertSessionHasNoErrors();

    expect($task->refresh()->completed)->toBeTrue()
        ->and($this->child->stats()->first()->xp)->toBe(9);
});

test('kinder dürfen keine familienaufgaben anlegen', function () {
    $this->actingAs($this->child)->post('/tasks', [
        'scope' => 'family', 'type' => 'todo', 'title' => 'Hack', 'category' => 'household', 'difficulty' => 1,
        'assignment_mode' => 'assigned', 'approval_mode' => 'none',
    ])->assertForbidden();
});

test('niemand kann fremde aufgaben abhaken', function () {
    [, , $foreignChild] = makeFamily();
    $foreignTask = makeTask($foreignChild);

    $this->actingAs($this->child)
        ->post('/assignments/'.$foreignTask->assignments()->first()->id.'/score/up')
        ->assertForbidden();
});

test('eltern sehen keine privaten aufgaben und dürfen sie nicht bearbeiten', function () {
    $task = makeTask($this->child, ['is_private' => true]);

    expect($this->parent->can('view', $task))->toBeFalse()
        ->and($this->parent->can('update', $task))->toBeFalse();
});

test('spielfehler kommen als kindgerechte meldung zurück', function () {
    $task = makeTask($this->child);
    $assignment = $task->assignments()->first();

    $this->actingAs($this->child)->post("/assignments/{$assignment->id}/score/up");
    $this->post("/assignments/{$assignment->id}/score/up")->assertSessionHasErrors('game');
});

test('eltern bestätigen erledigte familienaufgaben', function () {
    $task = makeTask($this->parent, [
        'scope' => 'family', 'type' => 'todo', 'title' => 'Müll rausbringen', 'assignment_mode' => 'assigned',
        'assignees' => [$this->child->id], 'approval_mode' => 'required', 'star_reward' => 2,
    ]);
    $this->actingAs($this->child)->post('/assignments/'.$task->assignments()->first()->id.'/score/up');
    $completion = TaskCompletion::first();

    $this->actingAs($this->child)->post("/completions/{$completion->id}/approve")->assertForbidden();

    $this->actingAs($this->parent)->get('/approvals')
        ->assertInertia(fn (Assert $page) => $page->component('family/Approvals')->has('completions', 1));
    $this->post("/completions/{$completion->id}/approve")->assertSessionHasNoErrors();

    expect($completion->refresh()->status)->toBe(CompletionStatus::Approved)
        ->and($this->child->stats()->first()->stars)->toBe(2);
});

test('eltern fremder familien können nichts freigeben', function () {
    [, $foreignParent] = makeFamily();
    $task = makeTask($this->parent, [
        'scope' => 'family', 'type' => 'todo', 'title' => 'X', 'assignment_mode' => 'assigned',
        'assignees' => [$this->child->id], 'approval_mode' => 'required',
    ]);
    $this->actingAs($this->child)->post('/assignments/'.$task->assignments()->first()->id.'/score/up');

    $this->actingAs($foreignParent)->post('/completions/'.TaskCompletion::first()->id.'/approve')->assertForbidden();
});

test('eltern legen kinderprofile an, kinder melden sich mit pin an', function () {
    $this->actingAs($this->parent)->post('/family/children', ['name' => 'Ben', 'birth_year' => now()->year - 12, 'pin' => '4321'])
        ->assertSessionHasNoErrors();
    auth()->logout();

    $ben = User::where('name', 'Ben')->first();
    $this->post('/family-login', ['code' => strtolower($this->family->login_code), 'user_id' => $ben->id, 'pin' => '0000'])
        ->assertSessionHasErrors('pin');
    $this->assertGuest();

    $this->post('/family-login', ['code' => $this->family->login_code, 'user_id' => $ben->id, 'pin' => '4321'])
        ->assertRedirect('/dashboard');
    $this->assertAuthenticatedAs($ben);
});

test('pin-login sperrt nach zu vielen versuchen', function () {
    foreach (range(1, 5) as $i) {
        $this->post('/family-login', ['code' => $this->family->login_code, 'user_id' => $this->child->id, 'pin' => '9999']);
    }

    $this->post('/family-login', ['code' => $this->family->login_code, 'user_id' => $this->child->id, 'pin' => '1234'])
        ->assertSessionHasErrors('pin');
    $this->assertGuest();
});

test('pin-login funktioniert nicht über fremde familiencodes', function () {
    [$otherFamily] = makeFamily();

    $this->post('/family-login', ['code' => $otherFamily->login_code, 'user_id' => $this->child->id, 'pin' => '1234'])
        ->assertSessionHasErrors('pin');
});

test('belohnungsshop: eltern legen an, kinder lösen ein', function () {
    $this->actingAs($this->child)->post('/shop/items', ['title' => 'Kino', 'star_cost' => 3])->assertForbidden();

    $this->actingAs($this->parent)->post('/shop/items', ['title' => 'Kino', 'star_cost' => 3, 'requires_approval' => true])
        ->assertSessionHasNoErrors();
    $item = $this->family->rewardItems()->first();

    $this->child->stats()->update(['stars' => 5]);
    $this->actingAs($this->child)->post("/shop/items/{$item->id}/redeem")->assertSessionHasNoErrors();

    expect($this->child->stats()->first()->stars)->toBe(2);
});

test('onboarding: beitritt per einladungscode', function () {
    $newbie = User::factory()->create();
    $invite = $this->family->invites()->create(['created_by' => $this->parent->id, 'role' => 'guest']);

    $this->actingAs($newbie)->post('/onboarding/join', ['code' => 'FALSCH'])->assertSessionHasErrors('code');
    $this->post('/onboarding/join', ['code' => $invite->code])->assertRedirect('/dashboard');

    expect($newbie->refresh()->family_id)->toBe($this->family->id);
});

test('neue familien dürfen nur admins gründen, solange die registrierung zu ist', function () {
    $newbie = User::factory()->create();

    $this->actingAs($newbie)->post('/onboarding/create', ['name' => 'Neu'])->assertForbidden();

    $newbie->forceFill(['is_admin' => true])->save();
    $this->post('/onboarding/create', ['name' => 'Neu'])->assertRedirect('/family');
});
