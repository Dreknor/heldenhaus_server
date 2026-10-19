<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Rewards\RewardService;
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
        abort_unless($user->isParent(), 403);
        $inFamily = fn ($q) => $q->where('family_id', $user->family_id);

        return response()->json([
            'completions' => TaskCompletion::with('task', 'user')->where('status', CompletionStatus::Pending)->whereHas('user', $inFamily)->oldest()->get()
                ->map(fn (TaskCompletion $c) => [
                    'id' => $c->id, 'task' => $c->task->title, 'user' => $c->user->name, 'user_id' => $c->user_id,
                    'stars' => $c->stars, 'source' => $c->source, 'created_at' => $c->created_at->toIso8601String(),
                ]),
            'redemptions' => RewardRedemption::with('rewardItem', 'user')->where('status', 'pending')->whereHas('user', $inFamily)->oldest()->get()
                ->map(fn (RewardRedemption $r) => [
                    'id' => $r->id, 'reward' => $r->rewardItem->title, 'user' => $r->user->name, 'user_id' => $r->user_id,
                    'stars' => $r->stars, 'created_at' => $r->created_at->toIso8601String(),
                ]),
        ]);
    }

    public function reviewCompletion(Request $request, TaskCompletion $completion, string $decision, ReviewCompletion $review): JsonResponse
    {
        abort_unless($request->user()->isParent() && $request->user()->sameFamilyAs($completion->user), 403);
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
            'items' => $request->user()->family->rewardItems()->where('active', true)->orderBy('star_cost')->get(),
            'stars' => $request->user()->stats()->first()->stars,
        ]);
    }

    public function redeem(Request $request, RewardItem $item, RewardService $rewards): JsonResponse
    {
        $redemption = $rewards->redeem($item, $request->user());

        return response()->json(['id' => $redemption->id, 'status' => $redemption->status, 'stars' => $request->user()->stats()->first()->stars]);
    }
}
