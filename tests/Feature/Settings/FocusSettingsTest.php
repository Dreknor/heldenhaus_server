<?php

use App\Enums\FamilyRole;
use App\Models\SupportSetting;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    [$this->family, $this->parent, $this->child] = makeFamily();
    $this->teen = joinFamily($this->family, FamilyRole::Child, ['birth_year' => (int) now()->year - 14, 'name' => 'Ben']);
});

it('zeigt die Seite Fokus & Darstellung mit den eigenen Werten und Themes', function () {
    $this->actingAs($this->child)->get('/settings/focus')->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Focus')
            ->where('settings.theme', 'classic')
            ->where('settings.feedback_level', 'full')
            ->where('ageGroup', 'child')
            // Kinder: Darstellung selbst, Spielregeln setzen die Eltern
            ->where('settings.can_change', fn ($fields) => collect($fields)->contains('theme') && collect($fields)->contains('dyslexia_font')
                && ! collect($fields)->contains('gentle_mode'))
            // Teen-Themes sind für Kinder nicht wählbar
            ->where('settings.themes', fn ($themes) => ! collect($themes)->pluck('key')->contains('neon')));
});

it('speichert Fokus- und Darstellungswerte über das Web-Formular', function () {
    $this->actingAs($this->teen)->from('/settings/focus')->put('/me/support-settings', [
        'feedback_level' => 'subtle',
        'sound' => false,
        'reduce_motion' => true,
        'timer_style' => 'digits',
        'theme' => 'neon',
        'high_contrast' => true,
        'text_scale' => 1.3,
        'dyslexia_font' => true,
        'color_vision' => 'deutan',
    ])->assertRedirect('/settings/focus')->assertSessionHasNoErrors();

    $s = SupportSetting::find($this->teen->id);
    expect($s->feedback_level)->toBe('subtle')
        ->and($s->sound)->toBeFalse()
        ->and($s->reduce_motion)->toBeTrue()
        ->and($s->timer_style)->toBe('digits')
        ->and($s->theme)->toBe('neon')
        ->and($s->high_contrast)->toBeTrue()
        ->and($s->text_scale)->toBe(1.3)
        ->and($s->dyslexia_font)->toBeTrue()
        ->and($s->color_vision)->toBe('deutan');
});

it('lehnt ungültige Werte und Teen-Themes für Kinder ab', function () {
    $this->actingAs($this->child)->put('/me/support-settings', ['theme' => 'neon'])->assertSessionHasErrors('theme');
    $this->put('/me/support-settings', ['text_scale' => 3])->assertSessionHasErrors('text_scale');
    $this->put('/me/support-settings', ['color_vision' => 'lila'])->assertSessionHasErrors('color_vision');
    // Spielregeln stellen bei Kindern die Eltern ein
    $this->put('/me/support-settings', ['gentle_mode' => true])->assertSessionHasErrors('game');

    expect(SupportSetting::find($this->child->id))->toBeNull();
});

it('lässt Eltern Darstellung und Theme für Kinder unter 13 setzen, aber nicht das Theme von Teens', function () {
    $this->actingAs($this->parent)->put("/members/{$this->child->id}/support-settings", [
        'theme' => 'ocean', 'reduce_motion' => true, 'feedback_level' => 'haptic', 'color_vision' => 'tritan', 'gentle_mode' => true,
    ])->assertSessionHasNoErrors();

    $s = SupportSetting::find($this->child->id);
    expect($s->theme)->toBe('ocean')
        ->and($s->reduce_motion)->toBeTrue()
        ->and($s->feedback_level)->toBe('haptic')
        ->and($s->color_vision)->toBe('tritan')
        ->and($s->set_by)->toBe($this->parent->id);

    // Das Theme eines Teens bleibt dessen Sache, Barrierefreiheit dürfen Eltern helfen einzustellen
    $this->put("/members/{$this->teen->id}/support-settings", ['theme' => 'midnight'])->assertSessionHasErrors('game');
    $this->put("/members/{$this->teen->id}/support-settings", ['text_scale' => 1.15])->assertSessionHasNoErrors();
    expect(SupportSetting::find($this->teen->id)->theme)->toBeNull();

    // Andere Kinder dürfen das nicht
    $this->actingAs($this->teen)->put("/members/{$this->child->id}/support-settings", ['theme' => 'candy'])->assertSessionHasErrors('game');
    expect(SupportSetting::find($this->child->id)->theme)->toBe('ocean');
});

it('liefert den Eltern im Familien-Formular die Felder aus ihrer Sicht', function () {
    $this->actingAs($this->parent)->get('/family/settings')->assertInertia(fn (Assert $page) => $page
        ->component('family/Settings')
        ->where('members', fn ($members) => collect($members)->firstWhere('id', $this->child->id)['supportSettings']['can_change'] !== null
            && in_array('gentle_mode', collect($members)->firstWhere('id', $this->child->id)['supportSettings']['can_change'], true)
            && in_array('theme', collect($members)->firstWhere('id', $this->child->id)['supportSettings']['can_change'], true)
            && ! in_array('theme', collect($members)->firstWhere('id', $this->teen->id)['supportSettings']['can_change'], true)));
});

it('teilt Darstellung und Rückmeldung als Shared-Props und setzt sie schon im HTML', function () {
    SupportSetting::create([
        'user_id' => $this->teen->id, 'theme' => 'retro', 'reduce_motion' => true, 'high_contrast' => true,
        'text_scale' => 1.15, 'dyslexia_font' => true, 'color_vision' => 'protan', 'feedback_level' => 'subtle', 'sound' => false,
    ]);

    $response = $this->actingAs($this->teen)->get('/settings/focus');
    $response->assertInertia(fn (Assert $page) => $page
        ->where('auth.display.theme', 'retro')
        ->where('auth.display.theme_dark', true)
        ->where('auth.display.reduce_motion', true)
        ->where('auth.display.high_contrast', true)
        ->where('auth.display.text_scale', 1.15)
        ->where('auth.display.dyslexia_font', true)
        ->where('auth.display.color_vision', 'protan')
        ->where('auth.feedback.level', 'subtle')
        ->where('auth.feedback.sound', false));

    // Kein Flackern: Klassen und Variablen stehen schon am <html>
    $html = $response->getContent();
    expect($html)->toContain('data-theme="retro"')
        ->toContain('data-cv="protan"')
        ->toContain('hh-reduce-motion')
        ->toContain('hh-contrast')
        ->toContain('hh-readable')
        ->toContain('font-size: 115%')
        ->toContain('rel="manifest"');
});

it('nutzt ohne eigene Wahl das Standard-Theme der Altersgruppe', function () {
    $this->actingAs($this->teen)->get('/settings/focus')
        ->assertInertia(fn (Assert $page) => $page->where('auth.display.theme', 'midnight')->where('auth.display.theme_dark', true));
    $this->actingAs($this->child)->get('/settings/focus')
        ->assertInertia(fn (Assert $page) => $page->where('auth.display.theme', 'classic')->where('auth.display.theme_dark', false));
});

it('liefert Manifest und Service Worker für die PWA aus', function () {
    $manifest = json_decode(file_get_contents(public_path('manifest.webmanifest')), true, flags: JSON_THROW_ON_ERROR);

    expect($manifest['name'])->toBe('Heldenhaus')
        ->and($manifest['theme_color'])->not->toBeEmpty()
        ->and(collect($manifest['icons'])->pluck('sizes')->all())->toContain('192x192', '512x512');
    foreach ($manifest['icons'] as $icon) {
        expect(file_exists(public_path(ltrim($icon['src'], '/'))))->toBeTrue();
    }
    expect(file_get_contents(public_path('sw.js')))->toContain('/api/');
});
