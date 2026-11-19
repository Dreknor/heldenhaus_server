<?php

namespace App\Http\Controllers;

use App\Domain\Achievements\AlbumService;
use App\Domain\Achievements\ChronicleService;
use App\Domain\Achievements\HeroYearService;
use App\Domain\Achievements\MasteryService;
use App\Domain\Achievements\TitleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Langzeitziele (§14.7) und Chronik (§14.8) – dieselben Daten für Web (Inertia) und App (API v1). */
class LongTermController extends Controller
{
    public function album(Request $request, AlbumService $albums): Response|JsonResponse
    {
        $user = $request->user();
        $albums->sync($user);
        $data = ['albums' => $albums->present($user)];
        AlbumService::markRewardsSeen($user);

        return $request->expectsJson() ? response()->json($data) : Inertia::render('Album', $data);
    }

    public function masteries(Request $request, MasteryService $masteries): JsonResponse
    {
        $user = $request->user();
        $masteries->refresh($user);

        return response()->json(['masteries' => MasteryService::present($user), 'title' => TitleService::display($user)]);
    }

    public function heroYear(Request $request, HeroYearService $heroYear): Response|JsonResponse
    {
        $data = ['year' => $heroYear->present($request->user())];

        return $request->expectsJson() ? response()->json($data) : Inertia::render('HeroYear', $data);
    }

    public function chronicle(Request $request, ChronicleService $chronicle): Response|JsonResponse
    {
        $data = $chronicle->present($request->user());

        return $request->expectsJson() ? response()->json($data) : Inertia::render('Chronicle', $data);
    }

    public function readChapter(Request $request, ChronicleService $chronicle, string $key): RedirectResponse|JsonResponse
    {
        $read = $chronicle->markRead($request->user(), $key);

        return $request->expectsJson() ? response()->json(['read' => $read]) : back();
    }

    /** Familienwappen wählen (Eltern), es erscheint in der Chronik. */
    public function crest(Request $request): RedirectResponse|JsonResponse
    {
        $family = $request->user()->family;
        $this->authorize('manage', $family);
        $data = $request->validate(['crest' => ['required', 'string', Rule::in(ChronicleService::CRESTS)]]);
        $family->update(['crest' => $data['crest']]);

        return $request->expectsJson() ? response()->json(['crest' => $family->crest]) : back()->with('success', 'Wappen gespeichert.');
    }
}
