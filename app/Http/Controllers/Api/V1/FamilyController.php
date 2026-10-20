<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Events\EventEngine;
use App\Domain\Rewards\RewardService;
use App\Domain\Rewards\RewardTemplates;
use App\Domain\Tasks\ReviewCompletion;
use App\Enums\CompletionStatus;
use App\Http\Controllers\Controller;
use App\Http\Presenters\GamePresenter;
use App\Models\RewardItem;
use App\Models\RewardRedemption;
use App\Models\TaskCompletion;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FamilyController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $family = $request->user()->family;

        return response()->json([
            'id' => $family->id,
            'name' => $family->name,
            'members' => $family->members()->with('stats')->orderBy('name')->get()->map(fn (User $m) => GamePresenter::member($m)),
        ]);
    }

    public function approvals(Request $request): JsonResponse
    {
        $user = $request->user();
        // Rollentausch-Tag (§12.4): Kinder geben die Freigaben, außer Sterne-Aufgaben
        $swap = ! $user->isParent() && EventEngine::isRunning($user->family_id, 'role_swap');
        abort_unless($user->isParent() || $swap, 403);
        $inFamily = fn ($q) => $q->where('family_id', $user->family_id);

        return response()->json([
            'completions' => TaskCompletion::with('task', 'user')->where('status', CompletionStatus::Pending)->whereHas('user', $inFamily)
                ->when($swap, fn ($q) => $q->where('stars', 0)->where('user_id', '!=', $user->id))->oldest()->get()
                ->map(fn (TaskCompletion $c) => [
                    'id' => $c->id, 'task' => $c->task->title, 'user' => $c->user->name, 'user_id' => $c->user_id,
                    'stars' => $c->stars, 'source' => $c->source, 'created_at' => $c->created_at->toIso8601String(),
                ]),
            'redemptions' => $swap ? [] : RewardRedemption::with('rewardItem', 'user')->where('status', 'pending')->whereHas('user', $inFamily)->oldest()->get()
                ->map(fn (RewardRedemption $r) => [
                    'id' => $r->id, 'reward' => $r->rewardItem->title, 'user' => $r->user->name, 'user_id' => $r->user_id,
                    'stars' => $r->stars, 'created_at' => $r->created_at->toIso8601String(),
                ]),
        ]);
    }

    public function reviewCompletion(Request $request, TaskCompletion $completion, string $decision, ReviewCompletion $review): JsonResponse
    {
        $user = $request->user();
        $swap = ! $user->isParent() && EventEngine::isRunning($user->family_id, 'role_swap')
            && $completion->stars === 0 && $completion->user_id !== $user->id;
        abort_unless(($user->isParent() || $swap) && $user->sameFamilyAs($completion->user), 403);
        $comment = $request->validate(['comment' => ['nullable', 'string', 'max:200']])['comment'] ?? null;

        $result = $decision === 'approve'
            ? $review->approve($completion, $request->user())
            : $review->reject($completion, $request->user(), $comment);

        return response()->json(['id' => $result->id, 'status' => $result->status->value]);
    }

    public function reviewRedemption(Request $request, RewardRedemption $redemption, string $decision, RewardService $rewards): JsonResponse
    {
        abort_unless($request->user()->isParent() && $request->user()->sameFamilyAs($redemption->user), 403);
        $comment = $request->validate(['comment' => ['nullable', 'string', 'max:200']])['comment'] ?? null;

        $result = $rewards->review($redemption, $request->user(), $decision === 'approve', $comment);

        return response()->json(['id' => $result->id, 'status' => $result->status]);
    }

    public function shop(Request $request): JsonResponse
    {
        return response()->json([
            // Eltern sehen auch ausgeblendete Belohnungen, um sie wieder einzuschalten
            'items' => $request->user()->family->rewardItems()->when(! $request->user()->isParent(), fn ($q) => $q->where('active', true))->orderBy('star_cost')->get(),
            'stars' => $request->user()->stats()->first()->stars,
        ]);
    }

    // ---------- Belohnungsshop verwalten (Eltern) ----------

    public function rewardTemplates(Request $request): JsonResponse
    {
        abort_unless($request->user()->isParent(), 403);

        return response()->json(['templates' => RewardTemplates::forFamily($request->user()->family)]);
    }

    public function adoptTemplates(Request $request): JsonResponse
    {
        abort_unless($request->user()->isParent(), 403);
        $data = $request->validate(['keys' => ['required', 'array', 'min:1', 'max:50'], 'keys.*' => ['string', 'max:40']]);

        return response()->json(['items' => RewardTemplates::adopt($request->user()->family, $data['keys'])], 201);
    }

    public function storeReward(Request $request): JsonResponse
    {
        abort_unless($request->user()->isParent(), 403);

        return response()->json($request->user()->family->rewardItems()->create($this->rewardData($request)), 201);
    }

    public function updateReward(Request $request, RewardItem $item): JsonResponse
    {
        abort_unless($request->user()->isParent() && $item->family_id === $request->user()->family_id, 403);
        $item->update($this->rewardData($request, partial: true));

        return response()->json($item);
    }

    public function destroyReward(Request $request, RewardItem $item): JsonResponse
    {
        abort_unless($request->user()->isParent() && $item->family_id === $request->user()->family_id, 403);
        // Nur ausblenden, damit eingelöste Belohnungen im Verlauf bleiben
        $item->update(['active' => false]);

        return response()->json(['ok' => true]);
    }

    private function rewardData(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'title' => [$required, 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:300'],
            'icon' => ['nullable', 'string', 'max:30'],
            'star_cost' => [$required, 'integer', 'min:1', 'max:10000'],
            'limit_per_week' => ['nullable', 'integer', 'min:1', 'max:100'],
            'requires_approval' => ['sometimes', 'boolean'],
            'allowance_cents' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'ha_action_key' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/'],
            'active' => ['sometimes', 'boolean'],
        ]);
    }

    public function redeem(Request $request, RewardItem $item, RewardService $rewards): JsonResponse
    {
        $redemption = $rewards->redeem($item, $request->user());

        return response()->json(['id' => $redemption->id, 'status' => $redemption->status, 'stars' => $request->user()->stats()->first()->stars]);
    }
}
