<?php

use App\Domain\Notifications\FcmClient;
use App\Domain\Rewards\RewardService;
use App\Domain\Tasks\ReviewCompletion;
use App\Jobs\SendPushNotification;
use App\Models\DeviceToken;
use App\Models\NotificationSetting;
use App\Models\RewardItem;
use App\Models\TaskCompletion;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    [$this->family, $this->parent, $this->child] = makeFamily();
});

function familyTaskNeedingApproval($parent, $child)
{
    return makeTask($parent, [
        'scope' => 'family', 'type' => 'todo', 'title' => 'Zimmer aufräumen', 'assignment_mode' => 'assigned',
        'assignees' => [$child->id], 'approval_mode' => 'required', 'star_reward' => 2,
    ]);
}

it('meldet Geräte an und hängt sie an das App-Token', function () {
    $token = $this->child->createToken('Pixel', ['app']);
    $headers = ['Authorization' => 'Bearer '.$token->plainTextToken, 'X-App-Build' => '12'];

    $this->postJson('/api/v1/devices', ['token' => 'fcm-abc'], $headers)->assertCreated();
    $this->postJson('/api/v1/devices', ['token' => 'fcm-abc'], $headers)->assertOk();
    expect(DeviceToken::count())->toBe(1)
        ->and(DeviceToken::first()->app_version)->toBe('12');

    // Abmelden widerruft das App-Token – und damit auch den Push auf diesem Gerät
    $this->postJson('/api/v1/auth/logout', [], $headers)->assertOk();
    expect(DeviceToken::count())->toBe(0);
});

it('benachrichtigt Eltern ohne Inhalte über neue Freigaben und das Kind über die Entscheidung', function () {
    Queue::fake();
    DeviceToken::create(['user_id' => $this->parent->id, 'token' => 'eltern']);
    DeviceToken::create(['user_id' => $this->child->id, 'token' => 'kind']);
    $task = familyTaskNeedingApproval($this->parent, $this->child);

    $this->actingAs($this->child)->post('/assignments/'.assignmentOf($task, $this->child)->id.'/score/up');

    Queue::assertPushed(SendPushNotification::class, fn ($job) => $job->type === 'approval_pending'
        && DeviceToken::find($job->deviceId)->token === 'eltern'
        && ! str_contains(json_encode($job->data), 'Zimmer'));

    app(ReviewCompletion::class)->approve(TaskCompletion::first(), $this->parent);
    Queue::assertPushed(SendPushNotification::class, fn ($job) => $job->type === 'approval_reviewed' && DeviceToken::find($job->deviceId)->token === 'kind');
});

it('schickt in der Ruhezeit nur eine stille Abgleich-Nachricht', function () {
    Queue::fake();
    $this->travelTo(now('Europe/Berlin')->setTime(21, 30));
    DeviceToken::create(['user_id' => $this->parent->id, 'token' => 'eltern']);
    NotificationSetting::create(['user_id' => $this->parent->id, 'quiet_start' => '20:00', 'quiet_end' => '07:00']);

    $item = RewardItem::create(['family_id' => $this->family->id, 'title' => 'Kino', 'star_cost' => 1]);
    $this->child->stats()->update(['stars' => 3]);
    app(RewardService::class)->redeem($item, $this->child);

    Queue::assertPushed(SendPushNotification::class, fn ($job) => $job->type === null && $job->data['type'] === 'redemption_pending' && $job->data['sync'] === '1');
});

it('verwaltet Einstellungen über die API', function () {
    Sanctum::actingAs($this->child, ['app']);

    $this->getJson('/api/v1/notifications/settings')->assertOk()->assertJsonPath('push_enabled', true);
    $this->putJson('/api/v1/notifications/settings', ['push_enabled' => true, 'quiet_start' => '20:00', 'quiet_end' => '07:00', 'muted' => ['level_up']])
        ->assertOk()->assertJsonPath('muted', ['level_up']);
    $this->putJson('/api/v1/notifications/settings', ['push_enabled' => true, 'muted' => ['quatsch']])->assertUnprocessable();

    $setting = NotificationSetting::find($this->child->id);
    expect($setting->allows('level_up'))->toBeFalse()
        ->and($setting->allows('approval_reviewed'))->toBeTrue()
        ->and($setting->isQuiet(now()->setTime(23, 0)->toImmutable()))->toBeTrue()
        ->and($setting->isQuiet(now()->setTime(6, 59)->toImmutable()))->toBeTrue()
        ->and($setting->isQuiet(now()->setTime(12, 0)->toImmutable()))->toBeFalse();
});

it('spricht FCM HTTP v1 mit Dienstkonto an und räumt ungültige Tokens ab', function () {
    $key = openssl_pkey_new(['private_key_bits' => 2048]);
    openssl_pkey_export($key, $pem);
    $path = tempnam(sys_get_temp_dir(), 'fcm');
    file_put_contents($path, json_encode([
        'project_id' => 'heldenhaus-test', 'client_email' => 'push@heldenhaus-test.iam.gserviceaccount.com',
        'private_key' => $pem, 'token_uri' => 'https://oauth2.googleapis.com/token',
    ]));
    config(['services.fcm.credentials' => $path]);

    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.test']),
        'fcm.googleapis.com/*' => Http::sequence()
            ->push(['name' => 'projects/heldenhaus-test/messages/1'])
            ->push(['error' => ['status' => 'NOT_FOUND', 'details' => [['errorCode' => 'UNREGISTERED']]]], 404),
    ]);

    $device = DeviceToken::create(['user_id' => $this->child->id, 'token' => 'kind']);
    (new SendPushNotification($device->id, 'level_up', ['type' => 'level_up', 'sync' => '1']))->handle(app(FcmClient::class));

    Http::assertSent(fn ($r) => str_contains($r->url(), 'projects/heldenhaus-test/messages:send')
        && $r->hasHeader('Authorization', 'Bearer ya29.test')
        && $r['message']['token'] === 'kind'
        && $r['message']['notification']['title'] === 'Level-up!');
    Http::assertSent(fn ($r) => str_contains($r->url(), 'oauth2') && count(explode('.', $r['assertion'])) === 3);

    (new SendPushNotification($device->id, null, ['type' => 'sync', 'sync' => '1']))->handle(app(FcmClient::class));
    expect(DeviceToken::find($device->id))->toBeNull();
    unlink($path);
});
