<?php

namespace App\Http\Controllers;

use App\Domain\Quests\QuestService;
use App\Models\Quest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Quest-Tafel: Familien- und Solo-Quests ansehen, starten, abbrechen (Web und API). */
class QuestController extends Controller
{
    public function __construct(private readonly QuestService $quests) {}

    public function index(Request $request): Response|JsonResponse
    {
        $data = $this->board($request);

        return $request->expectsJson() ? response()->json($data) : Inertia::render('Quests', $data);
    }

    public function start(Request $request): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'quest' => ['required', 'string'],
            'scope' => ['required', Rule::in(['family', 'solo'])],
            'reward_text' => ['nullable', 'string', 'max:120'],
        ]);

        $quest = $this->quests->start($request->user(), $data['quest'], $data['scope'], $data['reward_text'] ?? null);

        return $request->expectsJson()
            ? response()->json(QuestService::present($quest), 201)
            : back()->with('success', "Quest gestartet: {$quest->name}!");
    }

    public function abandon(Request $request, Quest $quest): RedirectResponse|JsonResponse
    {
        $this->quests->abandon($request->user(), $quest);

        return $request->expectsJson() ? response()->json(['ok' => true]) : back()->with('success', 'Quest abgebrochen.');
    }

    private function board(Request $request): array
    {
        $user = $request->user();

        return [
            'family' => QuestService::present($this->quests->activeFamily($user->family_id)),
            'solo' => QuestService::present($this->quests->activeSolo($user)),
            'familyOffers' => $this->quests->offers($user, 'family'),
            'soloOffers' => $this->quests->offers($user, 'solo'),
            'canStartFamily' => $user->isParent(),
            'categories' => config('game.categories'),
            'history' => Quest::where('family_id', $user->family_id)
                ->where(fn ($q) => $q->where('scope', 'family')->orWhere('user_id', $user->id))
                ->whereIn('status', ['completed', 'expired', 'abandoned'])
                ->latest('id')->limit(10)->get()
                ->map(fn (Quest $q) => [
                    'id' => $q->id, 'name' => $q->name, 'icon' => $q->icon, 'scope' => $q->scope,
                    'status' => $q->status, 'date' => ($q->completed_at ?? $q->updated_at)->toDateString(),
                ]),
        ];
    }
}
