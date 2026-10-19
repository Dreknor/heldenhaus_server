<?php

namespace App\Http\Controllers;

use App\Domain\Family\FamilyService;
use App\Models\FamilyInvite;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Nach der Registrierung: Familie gründen oder per Einladungscode beitreten. */
class OnboardingController extends Controller
{
    public function show(Request $request): Response|RedirectResponse
    {
        if ($request->user()->family_id) {
            return to_route('dashboard');
        }

        return Inertia::render('Onboarding', [
            'canCreate' => $request->user()->is_admin || config('heldenhaus.registration_open'),
        ]);
    }

    public function create(Request $request, FamilyService $families): RedirectResponse
    {
        abort_unless($request->user()->family_id === null, 409);
        abort_unless($request->user()->is_admin || config('heldenhaus.registration_open'), 403);

        $data = $request->validate(['name' => ['required', 'string', 'max:60']], [], ['name' => 'Familienname']);
        $families->createFamily($request->user(), $data['name']);

        return to_route('family.index')->with('success', 'Familie gegründet! Lege jetzt die Kinderprofile an.');
    }

    public function join(Request $request, FamilyService $families): RedirectResponse
    {
        abort_unless($request->user()->family_id === null, 409);

        $data = $request->validate(['code' => ['required', 'string']]);
        $invite = FamilyInvite::findUsable($data['code']);

        if ($invite === null) {
            throw ValidationException::withMessages(['code' => 'Dieser Code ist ungültig oder abgelaufen.']);
        }

        $families->acceptInvite($invite, $request->user());

        return to_route('dashboard')->with('success', "Willkommen bei {$invite->family->name}!");
    }
}
