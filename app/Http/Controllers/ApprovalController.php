<?php

namespace App\Http\Controllers;

use App\Domain\Rewards\RewardService;
use App\Domain\Tasks\ReviewCompletion;
use App\Enums\CompletionStatus;
use App\Models\RewardRedemption;
use App\Models\TaskCompletion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ApprovalController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->isParent(), 403);

        $inFamily = fn ($q) => $q->where('family_id', $user->family_id);

        return Inertia::render('family/Approvals', [
            'completions' => TaskCompletion::with('task', 'user')
                ->where('status', CompletionStatus::Pending)
                ->whereHas('user', $inFamily)
                ->oldest()
                ->get()
                ->map(fn (TaskCompletion $c) => [
                    'id' => $c->id,
                    'task' => $c->task->title,
                    'user' => $c->user->name,
                    'avatar_color' => $c->user->avatar_color,
                    'stars' => $c->stars,
                    'source' => $c->source,
                    'created_at' => $c->created_at->diffForHumans(),
                ]),
            'redemptions' => RewardRedemption::with('rewardItem', 'user')
                ->where('status', 'pending')
                ->whereHas('user', $inFamily)
                ->oldest()
                ->get()
                ->map(fn (RewardRedemption $r) => [
                    'id' => $r->id,
                    'reward' => $r->rewardItem->title,
                    'user' => $r->user->name,
                    'avatar_color' => $r->user->avatar_color,
                    'stars' => $r->stars,
                    'created_at' => $r->created_at->diffForHumans(),
                ]),
        ]);
    }

    public function reviewCompletion(Request $request, TaskCompletion $completion, string $decision, ReviewCompletion $review): RedirectResponse
    {
        $this->ensureParentOf($request, $completion->user);
        $comment = $request->validate(['comment' => ['nullable', 'string', 'max:200']])['comment'] ?? null;

        $decision === 'approve'
            ? $review->approve($completion, $request->user())
            : $review->reject($completion, $request->user(), $comment);

        return back();
    }

    public function reviewRedemption(Request $request, RewardRedemption $redemption, string $decision, RewardService $rewards): RedirectResponse
    {
        $this->ensureParentOf($request, $redemption->user);
        $comment = $request->validate(['comment' => ['nullable', 'string', 'max:200']])['comment'] ?? null;

        $rewards->review($redemption, $request->user(), $decision === 'approve', $comment);

        return back();
    }

    private function ensureParentOf(Request $request, $member): void
    {
        abort_unless($request->user()->isParent() && $request->user()->sameFamilyAs($member), 403);
    }
}
