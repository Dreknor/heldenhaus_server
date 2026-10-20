<?php

use App\Domain\Updater\UpdateService;
use Illuminate\Foundation\Http\MaintenanceModeBypassCookie;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    // Eigene Ablage, damit Tests den echten Updater-Status nicht berühren
    $this->storage = sys_get_temp_dir().DIRECTORY_SEPARATOR.'heldenhaus-updater-test-'.uniqid();
    config(['updater.storage_path' => $this->storage, 'updater.enabled' => true]);

    [, $this->admin] = makeFamily();
    $this->admin->forceFill(['is_admin' => true])->save();
});

afterEach(function () {
    File::deleteDirectory($this->storage);
});

function updaterState(string $storage): array
{
    return json_decode(file_get_contents($storage.DIRECTORY_SEPARATOR.'state.json'), true);
}

it('lässt nur Admins an die Update-Seite', function () {
    [, $parent] = makeFamily();

    $this->actingAs($parent)->get('/admin/updates')->assertForbidden();
    $this->actingAs($parent)->post('/admin/updates/start')->assertForbidden();

    $this->actingAs($this->admin)->get('/admin/updates')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('admin/Updates')->where('state.status', 'idle'));
});

it('lässt sich abschalten', function () {
    config(['updater.enabled' => false]);

    $this->actingAs($this->admin)->get('/admin/updates')->assertNotFound();
});

it('merkt ein Update vor und setzt das Bypass-Cookie', function () {
    $response = $this->actingAs($this->admin)->from('/admin/updates')->post('/admin/updates/start', ['full' => true]);

    $response->assertRedirect('/admin/updates')->assertSessionHas('success');

    $state = updaterState($this->storage);
    expect($state['status'])->toBe('requested')
        ->and($state['full'])->toBeTrue()
        ->and($state['requested_by'])->toBe($this->admin->name);

    // Cookie darf nicht verschlüsselt sein, sonst greift der Bypass nicht
    $cookie = $response->getCookie('laravel_maintenance', false);
    expect($cookie)->not->toBeNull()
        ->and(MaintenanceModeBypassCookie::isValid($cookie->getValue(), $state['secret']))->toBeTrue();
});

it('nimmt keine zweite Anforderung an', function () {
    $this->actingAs($this->admin)->post('/admin/updates/start');

    $this->actingAs($this->admin)->post('/admin/updates/start')->assertSessionHasErrors('game');
});

it('kann eine Anforderung zurücknehmen', function () {
    $this->actingAs($this->admin)->post('/admin/updates/start');

    $this->actingAs($this->admin)->post('/admin/updates/cancel')->assertSessionHas('success');

    expect(updaterState($this->storage)['status'])->toBe('idle')
        ->and(app(UpdateService::class)->isRequested())->toBeFalse();
});

it('verrät das Bypass-Geheimnis nicht im Status', function () {
    $this->actingAs($this->admin)->post('/admin/updates/start');

    $this->actingAs($this->admin)->getJson('/admin/updates/status')
        ->assertOk()
        ->assertJsonPath('state.status', 'requested')
        ->assertJsonMissingPath('state.secret');
});

it('meldet abgestürzte Läufe als fehlgeschlagen', function () {
    File::ensureDirectoryExists($this->storage);
    file_put_contents($this->storage.DIRECTORY_SEPARATOR.'state.json', json_encode([
        'status' => 'running',
        'steps' => ['migrate' => ['label' => 'Datenbank migrieren', 'status' => 'running', 'message' => null]],
    ]));

    $state = app(UpdateService::class)->state();

    expect($state['status'])->toBe('failed')
        ->and($state['steps']['migrate']['status'])->toBe('failed');
});

it('startet per Scheduler nur angeforderte Updates', function () {
    $this->artisan('updater:run --if-requested')->assertSuccessful();

    expect($this->storage.DIRECTORY_SEPARATOR.'state.json')->not->toBeFile();
});
