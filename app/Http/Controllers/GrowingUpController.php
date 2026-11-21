<?php

namespace App\Http\Controllers;

use App\Domain\Council\AgeTransitions;
use App\Domain\Council\ParentHelp;
use App\Domain\Features\Features;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/** Altersübergänge (§14.11): Karte „Du bist jetzt Teen“ und Erwachsenenprofil ab 16; Board-Hinweise wegklicken. */
class GrowingUpController extends Controller
{
    public function __construct(private readonly AgeTransitions $transitions) {}

    public function show(Request $request): Response|JsonResponse
    {
        $data = AgeTransitions::present($request->user());

        return $request->expectsJson() ? response()->json($data) : Inertia::render('GrowingUp', $data);
    }

    public function adultProfile(Request $request, Features $features): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ], [], ['email' => 'E-Mail', 'password' => 'Passwort']);

        $user = $this->transitions->activateAdultProfile($request->user(), $data['email'], $data['password'], $features);

        return $request->expectsJson()
            ? response()->json(AgeTransitions::present($user))
            : back()->with('success', 'Erwachsenenprofil eingerichtet. Du meldest dich ab jetzt mit deiner E-Mail an.');
    }

    /** Hinweis wegklicken: teen (Karte gesehen), adult (Angebot später), guide (Eltern-Anleitung). */
    public function dismiss(Request $request, string $key): RedirectResponse|JsonResponse
    {
        validator(['key' => $key], ['key' => [Rule::in(['teen', 'adult', 'guide'])]])->validate();
        $user = $request->user();
        match ($key) {
            'teen' => $this->transitions->teenCardSeen($user),
            'adult' => $this->transitions->dismissAdultOffer($user),
            'guide' => ParentHelp::markGuideSeen($user),
        };

        return $request->expectsJson() ? response()->json(['ok' => true]) : back();
    }
}
