<?php

namespace App\Http\Controllers;

use App\Domain\Rewards\RewardService;
use App\Models\RewardItem;
use App\Models\RewardRedemption;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Familien-Belohnungsshop: reale Belohnungen gegen Sterne. */
class RewardShopController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $family = $user->family;

        return Inertia::render('family/Shop', [
            'items' => $family->rewardItems()
                ->when(! $user->isParent(), fn ($q) => $q->where('active', true))
                ->orderBy('star_cost')
                ->get(),
            'history' => RewardRedemption::with('rewardItem', 'user')
                ->whereHas('user', fn ($q) => $q->where('family_id', $family->id))
                ->when(! $user->isParent(), fn ($q) => $q->where('user_id', $user->id))
                ->latest()
                ->limit(20)
                ->get()
                ->map(fn (RewardRedemption $r) => [
                    'id' => $r->id,
                    'reward' => $r->rewardItem->title,
                    'user' => $r->user->name,
                    'stars' => $r->stars,
                    'status' => $r->status,
                    'comment' => $r->review_comment,
                    'created_at' => $r->created_at->toDateString(),
                ]),
            'isParent' => $user->isParent(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $family = $request->user()->family;
        $this->authorize('manage', $family);
        $family->rewardItems()->create($this->validated($request));

        return back()->with('success', 'Belohnung angelegt.');
    }

    public function update(Request $request, RewardItem $item): RedirectResponse
    {
        $this->authorize('manage', $item->family);
        $item->update($this->validated($request));

        return back()->with('success', 'Belohnung gespeichert.');
    }

    public function destroy(Request $request, RewardItem $item): RedirectResponse
    {
        $this->authorize('manage', $item->family);
        $item->update(['active' => false]);

        return back();
    }

    public function redeem(Request $request, RewardItem $item, RewardService $rewards): RedirectResponse
    {
        $redemption = $rewards->redeem($item, $request->user());

        return back()->with('success', $redemption->status === 'pending'
            ? 'Eingelöst! Jetzt muss nur noch ein Elternteil bestätigen.'
            : "Eingelöst: {$item->title}!");
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:300'],
            'icon' => ['nullable', 'string', 'max:30'],
            'star_cost' => ['required', 'integer', 'min:1', 'max:10000'],
            'limit_per_week' => ['nullable', 'integer', 'min:1', 'max:100'],
            'requires_approval' => ['boolean'],
            'ha_action_key' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/'],
            'active' => ['boolean'],
        ], [], ['title' => 'Titel', 'star_cost' => 'Preis']);
    }
}
