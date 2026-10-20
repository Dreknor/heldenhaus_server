<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Notifications\PushNotifier;
use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use App\Models\NotificationSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** FCM-Geräte und Benachrichtigungs-Einstellungen der App. */
class DeviceController extends Controller
{
    /** Gerät anmelden (beim Start und wenn FCM ein neues Token vergibt). Ein Token gehört immer nur einer Person. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'platform' => ['nullable', Rule::in(['android', 'ios', 'web'])],
        ]);

        $device = DeviceToken::updateOrCreate(['token' => $data['token']], [
            'user_id' => $request->user()->id,
            'personal_access_token_id' => $request->user()->currentAccessToken()?->id,
            'platform' => $data['platform'] ?? 'android',
            'app_version' => $request->header('X-App-Build'),
            'last_seen_at' => now(),
        ]);

        return response()->json(['id' => $device->id], $device->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(Request $request): JsonResponse
    {
        $token = $request->validate(['token' => ['required', 'string', 'max:255']])['token'];
        DeviceToken::where('user_id', $request->user()->id)->where('token', $token)->delete();

        return response()->json(['ok' => true]);
    }

    public function settings(Request $request): JsonResponse
    {
        return response()->json($this->present($request->user()->notificationSetting ?? new NotificationSetting));
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'push_enabled' => ['required', 'boolean'],
            'quiet_start' => ['nullable', 'date_format:H:i', 'required_with:quiet_end'],
            'quiet_end' => ['nullable', 'date_format:H:i', 'required_with:quiet_start'],
            'muted' => ['nullable', 'array'],
            'muted.*' => [Rule::in(array_keys(PushNotifier::TYPES))],
        ]);

        $setting = NotificationSetting::updateOrCreate(['user_id' => $request->user()->id], [
            'push_enabled' => $data['push_enabled'],
            'quiet_start' => $data['quiet_start'] ?? null,
            'quiet_end' => $data['quiet_end'] ?? null,
            'muted' => array_values($data['muted'] ?? []) ?: null,
        ]);

        return response()->json($this->present($setting));
    }

    private function present(NotificationSetting $setting): array
    {
        return [
            'push_enabled' => (bool) $setting->push_enabled,
            'quiet_start' => $setting->quiet_start,
            'quiet_end' => $setting->quiet_end,
            'muted' => $setting->muted ?? [],
            'types' => collect(PushNotifier::TYPES)->map(fn ($t) => $t[0]),
        ];
    }
}
