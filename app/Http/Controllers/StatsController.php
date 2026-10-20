<?php

namespace App\Http\Controllers;

use App\Domain\Stats\StatsService;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Statistiken (Phase 8): eigene, eines Familienmitglieds (Eltern) und der ganzen Familie. */
class StatsController extends Controller
{
    public function me(Request $request): JsonResponse
    {
        return response()->json(StatsService::forUser($request->user()));
    }

    public function member(Request $request, User $member): JsonResponse
    {
        $viewer = $request->user();
        abort_unless($viewer->is($member) || ($viewer->isParent() && $viewer->sameFamilyAs($member)), 403);

        return response()->json(StatsService::forUser($member));
    }

    public function family(Request $request): JsonResponse
    {
        return response()->json(StatsService::forFamily($request->user()->family, $request->user()));
    }
}
