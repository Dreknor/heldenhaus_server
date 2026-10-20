<?php

namespace App\Http\Controllers;

use App\Domain\Features\Features;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Freischaltungen nach Level (Konzept §13.4). */
class FeatureController extends Controller
{
    public function me(Request $request): JsonResponse
    {
        return response()->json(Features::summary($request->user()));
    }

    public function introSeen(Request $request, string $key): JsonResponse
    {
        abort_unless(Features::get($key) !== null, 404);
        Features::introSeen($request->user(), $key);

        return response()->json(['ok' => true]);
    }

    public function starterDone(Request $request, string $key): JsonResponse
    {
        abort_unless(Features::get($key) !== null, 404);

        return response()->json(['rewarded' => Features::starterDone($request->user(), $key)]);
    }

    public function showAll(Request $request, Features $features): JsonResponse
    {
        $data = $request->validate(['on' => ['required', 'boolean']]);
        $features->showAll($request->user(), $data['on']);

        return response()->json(Features::summary($request->user()));
    }

    public function member(Request $request, User $member): JsonResponse
    {
        abort_unless($request->user()->isParent() && $request->user()->sameFamilyAs($member), 403);

        return response()->json(Features::forMember($member));
    }

    public function updateMember(Request $request, User $member, Features $features): JsonResponse
    {
        $data = $request->validate([
            'features' => ['sometimes', 'array'],
            'features.*' => ['nullable', 'in:early,defer'],
            'fast_start' => ['sometimes', 'boolean'],
        ]);

        return response()->json($features->override($request->user(), $member, $data['features'] ?? [], $data['fast_start'] ?? null));
    }
}
