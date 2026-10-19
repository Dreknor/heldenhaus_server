<?php

namespace App\Http\Controllers;

use App\Domain\Family\FamilyService;
use App\Models\Family;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Anmeldung für Kinder ohne E-Mail: Familiencode → Profil wählen → PIN.
 * Der Familiencode wird im Browser gemerkt (Familiengerät-Modus).
 */
class FamilyLoginController extends Controller
{
    public function show(Request $request): Response
    {
        $code = $request->query('code') ?? $request->cookie('family_code');
        $family = $code ? Family::where('login_code', Str::upper($code))->first() : null;

        return Inertia::render('auth/FamilyLogin', [
            'code' => $family?->login_code,
            'familyName' => $family?->name,
            'members' => $family
                ? $family->members()->whereNotNull('pin')->orderBy('name')->get()
                    ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'avatar_color' => $u->avatar_color])
                : [],
        ]);
    }

    public function login(Request $request, FamilyService $families): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string'],
            'user_id' => ['required', 'integer'],
            'pin' => ['required', 'string'],
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
        Auth::login($user, true);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'))
            ->withCookie(cookie()->forever('family_code', $family->login_code));
    }
}
