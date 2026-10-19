<?php

namespace App\Domain\Rewards;

use App\Domain\Gameplay\GameException;
use App\Domain\Gameplay\Progression;
use App\Domain\Social\FeedService;
use App\Enums\TaskType;
use App\Models\AllowanceEntry;
use App\Models\RewardItem;
use App\Models\RewardRedemption;
use App\Models\Task;
use App\Models\User;
use App\Models\UserStats;
use Illuminate\Support\Facades\DB;

class RewardService
{
    /** Persönliche Belohnung für Spiel-Gold kaufen. */
    public function buyPersonal(Task $reward, User $user): UserStats
    {
        if ($reward->type !== TaskType::Reward || $reward->owner_id !== $user->id) {
            throw new GameException('Diese Belohnung gehört dir nicht.');
        }

        return DB::transaction(function () use ($reward, $user) {
            $stats = UserStats::lockForUpdate()->findOrFail($user->id);

            if ($stats->gold < $reward->gold_cost) {
                throw new GameException('Dafür reicht dein Gold noch nicht.');
            }

            Progression::changeGold($stats, -$reward->gold_cost);
            $stats->save();

            $reward->completions()->create([
                'user_id' => $user->id,
                'direction' => 'purchase',
                'gold' => -$reward->gold_cost,
            ]);

            return $stats;
        });
    }

    /** Familienbelohnung mit Sternen einlösen. Sterne werden sofort reserviert und bei Ablehnung erstattet. */
    public function redeem(RewardItem $item, User $user): RewardRedemption
    {
        if (! $item->active || $item->family_id !== $user->family_id) {
            throw new GameException('Diese Belohnung gibt es gerade nicht.');
        }

        return DB::transaction(function () use ($item, $user) {
            $stats = UserStats::lockForUpdate()->findOrFail($user->id);

            if ($stats->stars < $item->star_cost) {
                throw new GameException('Dir fehlen noch '.($item->star_cost - $stats->stars).' Sterne.');
            }

            if ($item->limit_per_week !== null) {
                $thisWeek = $item->redemptions()
                    ->where('user_id', $user->id)
                    ->where('status', '!=', 'rejected')
                    ->where('created_at', '>=', now()->startOfWeek())
                    ->count();

                if ($thisWeek >= $item->limit_per_week) {
                    throw new GameException('Diese Woche hast du die Belohnung schon so oft eingelöst, wie es geht.');
                }
            }

            $stats->decrement('stars', $item->star_cost);

            $redemption = $item->redemptions()->create([
                'user_id' => $user->id,
                'stars' => $item->star_cost,
                'status' => $item->requires_approval ? 'pending' : 'approved',
                'reviewed_at' => $item->requires_approval ? null : now(),
            ]);

            if (! $item->requires_approval) {
                $this->afterApproval($redemption->load('rewardItem', 'user'));
            }

            return $redemption;
        });
    }

    public function review(RewardRedemption $redemption, User $reviewer, bool $approve, ?string $comment = null): RewardRedemption
    {
        return DB::transaction(function () use ($redemption, $reviewer, $approve, $comment) {
            $redemption = RewardRedemption::lockForUpdate()->findOrFail($redemption->id);

            if ($redemption->status !== 'pending') {
                throw new GameException('Diese Einlösung wurde schon bearbeitet.');
            }

            if (! $approve) {
                UserStats::whereKey($redemption->user_id)->increment('stars', $redemption->stars);
            }

            if ($approve) {
                $redemption->loadMissing('rewardItem', 'user');
                $this->afterApproval($redemption);
            }

            $redemption->update([
                'status' => $approve ? 'approved' : 'rejected',
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_comment' => $comment,
            ]);

            return $redemption;
        });
    }

    /** Feed-Eintrag und ggf. Buchung auf dem Taschengeld-Konto. */
    private function afterApproval(RewardRedemption $redemption): void
    {
        $item = $redemption->rewardItem;
        $user = $redemption->user;

        if ($item->allowance_cents) {
            AllowanceEntry::create([
                'family_id' => $item->family_id,
                'user_id' => $user->id,
                'amount_cents' => $item->allowance_cents,
                'description' => "Belohnung: {$item->title}",
                'reward_redemption_id' => $redemption->id,
            ]);
        }

        app(FeedService::class)->post($item->family_id, $user, 'reward_redeemed', '🎁', "{$user->name} hat „{$item->title}“ eingelöst");
    }
}
