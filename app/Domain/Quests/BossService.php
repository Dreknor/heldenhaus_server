<?php

namespace App\Domain\Quests;

use App\Domain\Gameplay\GameException;
use App\Domain\Social\FeedService;
use App\Enums\FamilyRole;
use App\Models\Family;
use App\Models\FamilyQuest;
use App\Models\Item;
use App\Models\TaskCompletion;
use App\Models\User;
use App\Models\UserItem;
use App\Models\UserStats;
use Illuminate\Support\Facades\DB;

/**
 * Familien-Boss (Konzept §3.5): Alle erledigten Aufgaben aller Mitglieder machen Schaden,
 * verpasste Dailies heilen ihn. Bis Phase 7 ist der Boss eine Lebensleiste.
 */
class BossService
{
    public const DEFEAT_GOLD = 20;

    public static function catalog(): array
    {
        return collect(json_decode(file_get_contents(database_path('content/bosses.json')), true, flags: JSON_THROW_ON_ERROR))
            ->keyBy('key')
            ->all();
    }

    public function active(?int $familyId): ?FamilyQuest
    {
        if (! $familyId) {
            return null;
        }

        FamilyQuest::where('family_id', $familyId)->where('status', 'active')->where('ends_at', '<=', now())
            ->update(['status' => 'expired']);

        return FamilyQuest::where('family_id', $familyId)->where('status', 'active')->latest('id')->first();
    }

    public function start(User $parent, string $bossKey, ?string $rewardText = null): FamilyQuest
    {
        $boss = self::catalog()[$bossKey] ?? throw new GameException('Diesen Boss gibt es nicht.');

        if ($this->active($parent->family_id)) {
            throw new GameException('Es läuft schon ein Boss-Kampf.');
        }

        // Mehr Mitspieler → mehr Boss-HP
        $players = $parent->family->members()->where('family_role', '!=', FamilyRole::Guest->value)->count();
        $hp = (int) round($boss['hp'] * max(1, $players / 2));

        return FamilyQuest::create([
            'family_id' => $parent->family_id,
            'boss_key' => $bossKey,
            'name' => $boss['name'],
            'icon' => $boss['icon'],
            'max_hp' => $hp,
            'hp' => $hp,
            'reward_text' => $rewardText,
            'ends_at' => now()->addDays($boss['days']),
            'started_by' => $parent->id,
        ]);
    }

    /** Schaden durch einen Abschluss; gibt den tatsächlichen Schaden zurück. */
    public function hit(TaskCompletion $completion, User $user, float $damage): float
    {
        $quest = $this->active($user->family_id);
        if ($quest === null || $damage <= 0) {
            return 0.0;
        }

        return DB::transaction(function () use ($quest, $completion, $user, $damage) {
            $quest = FamilyQuest::lockForUpdate()->find($quest->id);
            $dealt = round(min($damage, $quest->hp), 2);

            $quest->hp = round($quest->hp - $dealt, 2);
            $quest->contributions()->create(['user_id' => $user->id, 'task_completion_id' => $completion->id, 'damage' => $dealt]);
            $completion->forceFill(['boss_damage' => $dealt])->save();

            if ($quest->hp <= 0) {
                $this->defeat($quest);
            }
            $quest->save();

            return $dealt;
        });
    }

    /** Rückgängig/Ablehnung: Schaden zurück an den Boss, solange der Kampf läuft. */
    public function revert(TaskCompletion $completion): void
    {
        if ($completion->boss_damage <= 0) {
            return;
        }

        $contribution = DB::table('family_quest_contributions')->where('task_completion_id', $completion->id)->first();
        if ($contribution) {
            FamilyQuest::whereKey($contribution->family_quest_id)->where('status', 'active')->increment('hp', $completion->boss_damage);
            DB::table('family_quest_contributions')->where('id', $contribution->id)->delete();
        }

        $completion->forceFill(['boss_damage' => 0])->save();
    }

    /** Verpasste Dailies heilen den Boss (statt andere Familienmitglieder zu verletzen). */
    public function heal(?int $familyId, float $amount): void
    {
        $quest = $this->active($familyId);
        if ($quest && $amount > 0) {
            $quest->update(['hp' => min($quest->max_hp, $quest->hp + $amount)]);
        }
    }

    private function defeat(FamilyQuest $quest): void
    {
        app(FeedService::class)->post($quest->family_id, null, 'boss_defeated', $quest->icon, "Sieg! Die Familie hat {$quest->name} besiegt".($quest->reward_text ? " – {$quest->reward_text}!" : '!'));
        $quest->status = 'defeated';
        $quest->defeated_at = now();
        $quest->hp = 0;

        $members = Family::find($quest->family_id)->members()->where('family_role', '!=', FamilyRole::Guest->value)->pluck('id');
        $loot = Item::where('key', 'stardust')->where('active', true)->first();

        foreach ($members as $memberId) {
            UserStats::whereKey($memberId)->increment('gold', self::DEFEAT_GOLD);
            if ($loot) {
                UserItem::firstOrCreate(['user_id' => $memberId, 'item_id' => $loot->id])->increment('quantity');
            }
        }
    }

    public static function present(?FamilyQuest $quest): ?array
    {
        if ($quest === null) {
            return null;
        }

        return [
            'id' => $quest->id,
            'name' => $quest->name,
            'icon' => $quest->icon,
            'hp' => round($quest->hp, 1),
            'max_hp' => $quest->max_hp,
            'reward_text' => $quest->reward_text,
            'status' => $quest->status,
            'ends_at' => $quest->ends_at->toIso8601String(),
            // Beiträge ohne Rangliste (Kooperation statt Konkurrenz, Konzept §1)
            'contributions' => $quest->contributions()
                ->selectRaw('user_id, sum(damage) as damage')
                ->groupBy('user_id')
                ->with('user:id,name,avatar_color')
                ->get()
                ->map(fn ($c) => ['name' => $c->user?->name, 'avatar_color' => $c->user?->avatar_color, 'damage' => round((float) $c->damage, 1)]),
        ];
    }
}
