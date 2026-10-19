<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Family\FamilyService;
use App\Http\Controllers\Controller;
use App\Http\Presenters\GamePresenter;
use App\Models\Family;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /** Eltern/Teens mit E-Mail und Passwort. */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:100'],
        ]);

        $user = User::where('email', Str::lower($data['email']))->first();

        if (! $user || ! $user->password || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'E-Mail oder Passwort stimmen nicht.']);
        }

        return $this->issueToken($user, $data['device_name']);
    }

    /** Familiengerät/Kinder-App: Familiencode (aus QR-Code) → Profile anzeigen. */
    public function family(string $code): JsonResponse
    {
        $family = Family::where('login_code', Str::upper($code))->firstOrFail();

        return response()->json([
            'name' => $family->name,
            'members' => $family->members()->whereNotNull('pin')->orderBy('name')->get()
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'avatar_color' => $u->avatar_color]),
        ]);
    }

    public function pin(Request $request, FamilyService $families): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string'],
            'user_id' => ['required', 'integer'],
            'pin' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:100'],
        ]);

        $key = 'pin-login:'.$data['user_id'].'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['pin' => 'Zu viele Versuche. Warte '.RateLimiter::availableIn($key).' Sekunden.']);
        }

        $family = Family::where('login_code', Str::upper($data['code']))->first();
        $user = $family ? $families->attemptPinLogin($family, (int) $data['user_id'], $data['pin']) : null;

        if ($user === null) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['pin' => 'Die PIN stimmt nicht.']);
        }

        RateLimiter::clear($key);

        return $this->issueToken($user, $data['device_name']);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['ok' => true]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('stats', 'family');

        return response()->json([
            'user' => [
                ...GamePresenter::member($user),
                'email' => $user->email,
                'theme' => $user->theme,
                'is_admin' => $user->is_admin,
            ],
            'family' => $user->family ? ['id' => $user->family->id, 'name' => $user->family->name] : null,
        ]);
    }

    private function issueToken(User $user, string $device): JsonResponse
    {
        $token = $user->createToken($device, ['app'])->plainTextToken;

        return response()->json(['token' => $token, 'user' => GamePresenter::member($user->load('stats'))]);
    }
}
