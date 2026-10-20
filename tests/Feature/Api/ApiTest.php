<?php

use App\Enums\CompletionStatus;
use App\Models\AppRelease;
use App\Models\TaskCompletion;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    [$this->family, $this->parent, $this->child] = makeFamily();
});

test('kinder melden sich per familiencode und pin an und erhalten ein app-token', function () {
    $this->getJson("/api/v1/auth/family/{$this->family->login_code}")
        ->assertOk()
        ->assertJsonPath('members.0.name', 'Lea');

    $token = $this->postJson('/api/v1/auth/pin', [
        'code' => $this->family->login_code, 'user_id' => $this->child->id, 'pin' => '1234', 'device_name' => 'Leas Handy',
    ])->assertOk()->json('token');

    $this->withToken($token)->getJson('/api/v1/me')->assertOk()->assertJsonPath('user.name', 'Lea');
});

test('falsche pin gibt 422', function () {
    $this->postJson('/api/v1/auth/pin', [
        'code' => $this->family->login_code, 'user_id' => $this->child->id, 'pin' => '0000', 'device_name' => 'x',
    ])->assertStatus(422)->assertJsonValidationErrors('pin');
});

test('eltern melden sich mit e-mail an', function () {
    $this->postJson('/api/v1/auth/login', ['email' => $this->parent->email, 'password' => 'password', 'device_name' => 'Tablet'])
        ->assertOk()->assertJsonStructure(['token', 'user']);
});

test('das board enthält aufgaben und werte', function () {
    makeTask($this->child, ['type' => 'daily', 'title' => 'Zähne putzen']);
    Sanctum::actingAs($this->child, ['app']);

    $this->getJson('/api/v1/board')
        ->assertOk()
        ->assertJsonPath('dailies.0.title', 'Zähne putzen')
        ->assertJsonPath('stats.level', 1)
        ->assertJsonStructure(['habits', 'todos', 'rewards', 'pool', 'cronPending', 'server_time', 'appearance' => ['avatar', 'seed', 'equipment'], 'quests', 'companion']);
});

test('spielfehler kommen als json 422', function () {
    $task = makeTask($this->child);
    Sanctum::actingAs($this->child, ['app']);
    $id = $task->assignments()->first()->id;

    $this->postJson("/api/v1/assignments/{$id}/score/up")->assertOk()->assertJsonPath('xp', 6);
    $this->postJson("/api/v1/assignments/{$id}/score/up")->assertStatus(422)->assertJsonPath('message', 'Schon erledigt – super!');
});

test('offline-aktionen werden genau einmal gewertet', function () {
    $task = makeTask($this->child, ['type' => 'habit']);
    $assignmentId = $task->assignments()->first()->id;
    Sanctum::actingAs($this->child, ['app']);
    $actions = [
        ['client_uuid' => (string) Str::uuid(), 'type' => 'score', 'assignment_id' => $assignmentId, 'direction' => 'up'],
        ['client_uuid' => (string) Str::uuid(), 'type' => 'score', 'assignment_id' => $assignmentId, 'direction' => 'up'],
        ['client_uuid' => (string) Str::uuid(), 'type' => 'score', 'assignment_id' => 999999, 'direction' => 'up'],
    ];

    $this->postJson('/api/v1/sync/actions', ['actions' => $actions])
        ->assertOk()
        ->assertJsonPath('results.0.ok', true)
        ->assertJsonPath('results.2.ok', false);

    // Erneutes Senden (z. B. nach Verbindungsabbruch) zählt nicht doppelt
    $this->postJson('/api/v1/sync/actions', ['actions' => array_slice($actions, 0, 2)])
        ->assertJsonPath('results.0.replayed', true);

    expect($task->assignments()->first()->counter_up)->toBe(2);
});

test('home-assistant-tokens dürfen keine app-endpunkte nutzen und umgekehrt', function () {
    Sanctum::actingAs($this->parent, ['ha']);
    $this->getJson('/api/v1/board')->assertForbidden();
    $this->getJson('/api/v1/ha/state')->assertOk();

    Sanctum::actingAs($this->parent, ['app']);
    $this->getJson('/api/v1/ha/state')->assertForbidden();
});

test('home assistant erledigt aufgaben per ereignis – verifiziert', function () {
    $task = makeTask($this->parent, [
        'scope' => 'family', 'type' => 'daily', 'title' => 'Katze füttern', 'assignment_mode' => 'assigned',
        'assignees' => [$this->child->id], 'approval_mode' => 'required', 'star_reward' => 2, 'ha_event_key' => 'nfc_feed_cat',
    ]);
    Sanctum::actingAs($this->parent, ['ha']);

    $this->postJson('/api/v1/ha/events', ['event' => 'nfc_feed_cat'])
        ->assertOk()
        ->assertJsonPath('handled.0.user', 'Lea');

    expect(TaskCompletion::first()->status)->toBe(CompletionStatus::Auto)
        ->and(TaskCompletion::first()->source)->toBe('ha')
        ->and($this->child->stats()->first()->stars)->toBe(2);

    // Zweites Ereignis am selben Tag: schon erledigt, nichts passiert
    $this->postJson('/api/v1/ha/events', ['event' => 'nfc_feed_cat'])->assertJsonCount(0, 'handled');

    $lea = collect($this->getJson('/api/v1/ha/state')->json('members'))->firstWhere('name', 'Lea');
    expect($lea['all_dailies_done'])->toBeTrue()->and($lea['stats']['stars'])->toBe(2);
});

test('ha-ereignisse wirken nur in der eigenen familie', function () {
    [, $otherParent] = makeFamily();
    makeTask($this->parent, [
        'scope' => 'family', 'type' => 'todo', 'title' => 'X', 'assignment_mode' => 'assigned',
        'assignees' => [$this->child->id], 'approval_mode' => 'none', 'ha_event_key' => 'secret_event',
    ]);
    Sanctum::actingAs($otherParent, ['ha']);

    $this->postJson('/api/v1/ha/events', ['event' => 'secret_event'])->assertJsonCount(0, 'handled');
});

test('app-updater: neueste version, download und pflicht-update', function () {
    Storage::fake('local');
    Cache::flush();
    $this->parent->forceFill(['is_admin' => true])->save();
    Sanctum::actingAs($this->parent, ['app']);

    $this->postJson('/api/v1/admin/app-releases', [
        'apk' => UploadedFile::fake()->create('app.apk', 500),
        'version_name' => '1.1.0', 'version_code' => 5, 'channel' => 'stable', 'changelog' => 'Neu: Haustiere', 'min_supported_code' => 3,
    ])->assertCreated();

    $version = $this->getJson('/api/v1/app/version?build=4')
        ->assertOk()
        ->assertJsonPath('latest.version_code', 5)
        ->assertJsonPath('update_available', true)
        ->assertJsonPath('update_required', false)
        ->json();

    $this->get($version['latest']['download_url'])->assertOk();
    $this->get(route('api.v1.app.download', ['release' => AppRelease::first()->id]))->assertForbidden();

    $this->getJson('/api/v1/me', ['X-App-Build' => '2'])->assertStatus(426)->assertJsonPath('min_supported', 3);
    $this->getJson('/api/v1/me', ['X-App-Build' => '3'])->assertOk();
});

test('nur admins laden app-releases hoch', function () {
    Sanctum::actingAs($this->parent, ['app']);

    $this->postJson('/api/v1/admin/app-releases', [
        'apk' => UploadedFile::fake()->create('app.apk', 10), 'version_name' => '1', 'version_code' => 1, 'channel' => 'stable',
    ])->assertForbidden();
});
