<?php

namespace App\Http\Controllers;

use App\Domain\Achievements\AchievementService;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Erfolge ansehen (eigene und die der Familie) und die Feier neuer Erfolge abschließen (Web und API). */
class AchievementController extends Controller
{
    public function __construct(private readonly AchievementService $achievements) {}

    public function index(Request $request): Response|JsonResponse
    {
        $user = $request->user();
        $member = $request->integer('member') ?: $user->id;
        $member = User::where('family_id', $user->family_id)->findOrFail($member);

        $data = [
            'member' => $member->only(['id', 'name']),
            'achievements' => $this->achievements->present($member),
            'members' => User::where('family_id', $user->family_id)->orderBy('id')->get(['id', 'name', 'avatar_color'])
                ->map(fn (User $u) => [...$u->only(['id', 'name', 'avatar_color']), 'count' => $u->achievements()->count()])
                ->values(),
        ];

        return $request->expectsJson() ? response()->json($data) : Inertia::render('Achievements', $data);
    }

    public function seen(Request $request): RedirectResponse|JsonResponse
    {
        $count = $this->achievements->markSeen($request->user());

        return $request->expectsJson() ? response()->json(['seen' => $count]) : back();
    }
}
