<?php

use App\Domain\Council\AgeTransitions;
use App\Domain\Family\WeeklyReport;
use App\Models\Task;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    [$this->family, $this->parent, $this->child] = makeFamily();
});

it('mit 13 kommt die Karte „Du bist jetzt Teen“ mit Themes, privaten Aufgaben und Chat nach Elternfreigabe', function () {
    expect(AgeTransitions::teenCardDue($this->child))->toBeFalse();

    // Jahreswechsel des Geburtsjahrs bzw. Eltern passen das Geburtsjahr an
    $this->actingAs($this->parent)->put("/family/members/{$this->child->id}", ['name' => 'Lea', 'birth_year' => now()->year - 13]);
    $lea = $this->child->refresh();
    expect(AgeTransitions::teenCardDue($lea))->toBeTrue();

    $this->actingAs($lea)->get('/dashboard')->assertInertia(fn ($p) => $p->where('councilHints.0.key', 'teen'));
    $this->get('/me/growing-up')->assertOk()->assertInertia(fn ($p) => $p->component('GrowingUp')
        ->where('teen_card', true)->where('chat_allowed', false)
        ->where('themes', fn ($t) => collect($t)->contains('key', 'midnight')));

    $this->post('/me/hints/teen/dismiss')->assertRedirect();
    expect(AgeTransitions::teenCardDue($lea->refresh()))->toBeFalse();
});

it('ab 16: Erwachsenenprofil mit eigener Anmeldung und „Alles sofort zeigen“', function () {
    $teen = joinFamily($this->family, attributes: ['birth_year' => now()->year - 15, 'email' => null]);
    expect(AgeTransitions::adultOfferDue($teen))->toBeFalse();
    Sanctum::actingAs($teen, ['app']);
    $this->postJson('/api/v1/me/adult-profile', ['email' => 'max@example.org', 'password' => 'geheim-123', 'password_confirmation' => 'geheim-123'])
        ->assertStatus(422);

    $teen->update(['birth_year' => now()->year - 16]);
    $this->getJson('/api/v1/me/age-transition')->assertOk()->assertJsonPath('adult_offer', true);
    $this->postJson('/api/v1/me/adult-profile', ['email' => $this->parent->email, 'password' => 'geheim-123', 'password_confirmation' => 'geheim-123'])
        ->assertStatus(422);
    $this->postJson('/api/v1/me/adult-profile', ['email' => 'max@example.org', 'password' => 'geheim-123', 'password_confirmation' => 'geheim-123'])
        ->assertOk()->assertJsonPath('adult_profile', true)->assertJsonPath('show_all', true);

    $teen->refresh();
    expect($teen->isIndependent())->toBeTrue()->and($teen->email)->toBe('max@example.org');
    // Eigene Anmeldung per E-Mail und Passwort
    $this->postJson('/api/v1/auth/login', ['email' => 'max@example.org', 'password' => 'geheim-123', 'device_name' => 'Handy'])->assertOk();
});

it('Erwachsenenprofil: Eltern sehen nur noch Familienaufgaben und Sterne', function () {
    $teen = joinFamily($this->family, attributes: ['birth_year' => now()->year - 16]);
    $teen->forceFill(['adult_profile_at' => now()])->save();
    $personal = makeTask($teen, ['title' => 'Bewerbung schreiben']);
    $family = makeTask($this->parent, ['scope' => 'family', 'type' => 'todo', 'title' => 'Müll rausbringen', 'category' => 'household',
        'assignment_mode' => 'assigned', 'assignees' => [$teen->id], 'approval_mode' => 'none', 'star_reward' => 2]);

    expect($this->parent->can('view', $personal))->toBeFalse()
        ->and($this->parent->can('view', $family))->toBeTrue()
        ->and($this->parent->can('view', makeTask($this->child)))->toBeTrue();

    Sanctum::actingAs($this->parent, ['app']);
    $this->getJson("/api/v1/members/{$teen->id}/stats")->assertForbidden();
    $this->getJson("/api/v1/members/{$teen->id}/support-settings")->assertStatus(422);
    $this->getJson("/api/v1/members/{$teen->id}/features")->assertForbidden();
    $this->getJson("/api/v1/members/{$this->child->id}/stats")->assertOk();

    $row = collect(WeeklyReport::for($this->family)['members'])->firstWhere('id', $teen->id);
    expect($row['dailies_due'])->toBeNull()->and($row['xp'])->toBeNull()->and($row)->toHaveKeys(['family_tasks_done', 'stars']);

    // Eltern können Name oder PIN nicht mehr ändern
    $this->actingAs($this->parent)->put("/family/members/{$teen->id}", ['name' => 'Anders', 'pin' => '9999']);
    expect($teen->refresh()->name)->not->toBe('Anders');

    $this->actingAs($this->parent)->get('/family/settings')->assertInertia(fn ($p) => $p
        ->where('members', fn ($m) => ! isset(collect($m)->firstWhere('id', $teen->id)['supportSettings'])
            && collect($m)->firstWhere('id', $teen->id)['adult_profile'] === true));
    expect(Task::find($personal->id))->not->toBeNull();
    expect(User::find($teen->id)->stats)->not->toBeNull();
});
