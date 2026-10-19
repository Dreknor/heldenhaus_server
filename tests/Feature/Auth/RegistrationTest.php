<?php

use App\Enums\FamilyRole;
use App\Models\User;

test('registration screen can be rendered', function () {
    $this->get('/register')->assertOk();
});

test('registrierung ohne einladungscode ist nicht möglich', function () {
    $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasErrors('invite');

    $this->assertGuest();
});

test('mit einladungscode tritt man direkt der familie bei', function () {
    [$family, $parent] = makeFamily();
    $invite = $family->invites()->create(['created_by' => $parent->id, 'role' => FamilyRole::Parent]);

    $this->post('/register', [
        'invite' => strtolower($invite->code),
        'name' => 'Zweites Elternteil',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect(route('dashboard', absolute: false));

    $user = User::where('email', 'test@example.com')->first();
    expect($user->family_id)->toBe($family->id)
        ->and($user->family_role)->toBe(FamilyRole::Parent)
        ->and($invite->refresh()->used_at)->not->toBeNull();
});
