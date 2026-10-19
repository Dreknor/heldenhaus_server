<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Domain\Family\FamilyService;
use App\Models\FamilyInvite;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    /**
     * Show the registration page.
     */
    public function create(): Response
    {
        return Inertia::render('auth/Register', [
            'inviteRequired' => ! config('heldenhaus.registration_open'),
            'invite' => request()->query('invite'),
        ]);
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:'.User::class,
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'invite' => [config('heldenhaus.registration_open') ? 'nullable' : 'required', 'string'],
        ], [], ['invite' => 'Einladungscode']);

        $invite = FamilyInvite::findUsable($request->invite);
        if ($request->filled('invite') && $invite === null) {
            throw ValidationException::withMessages(['invite' => 'Dieser Einladungscode ist ungültig oder abgelaufen.']);
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        if ($invite) {
            app(FamilyService::class)->acceptInvite($invite, $user);
        }

        event(new Registered($user));

        Auth::login($user);

        return to_route('dashboard');
    }
}
