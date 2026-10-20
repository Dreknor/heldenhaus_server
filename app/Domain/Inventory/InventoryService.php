<?php

namespace App\Domain\Inventory;

use App\Domain\Achievements\AchievementService;
use App\Domain\Gameplay\Attributes;
use App\Domain\Gameplay\Formulas;
use App\Domain\Gameplay\GameException;
use App\Domain\Gameplay\Progression;
use App\Domain\Social\FeedService;
use App\Models\Item;
use App\Models\Room;
use App\Models\User;
use App\Models\UserEquipment;
use App\Models\UserItem;
use App\Models\UserPet;
use App\Models\UserStats;
use Illuminate\Support\Facades\DB;

/** Gold-Shop, Ausrüstung, Tränke, Haustiere (Konzept §2.3). */
class InventoryService
{
    public function buy(User $user, Item $item, int $quantity = 1): UserStats
    {
        if (! $item->active || $item->price_gold === null) {
            throw new GameException('Das gibt es nicht im Shop.');
        }

        return DB::transaction(function () use ($user, $item, $quantity) {
            $stats = UserStats::lockForUpdate()->findOrFail($user->id);

            if ($stats->level < $item->min_level) {
                throw new GameException("Das gibt es erst ab Level {$item->min_level}.");
            }

            if ($item->type === 'equipment' && $this->quantity($user, $item) > 0) {
                throw new GameException('Das hast du schon.');
            }

            $price = $item->price_gold * $quantity;
            if ($stats->gold < $price) {
                throw new GameException('Dafür reicht dein Gold noch nicht.');
            }

            Progression::changeGold($stats, -$price);
            $stats->save();
            $this->add($user, $item, $quantity);

            return $stats;
        });
    }

    public function equip(User $user, Item $item): void
    {
        if ($item->type !== 'equipment' || ! $item->slot) {
            throw new GameException('Das kann man nicht anziehen.');
        }

        if ($this->quantity($user, $item) < 1) {
            throw new GameException('Das besitzt du nicht.');
        }

        UserEquipment::updateOrCreate(['user_id' => $user->id, 'slot' => $item->slot], ['item_id' => $item->id]);
        $this->clampToMax($user);
        app(AchievementService::class)->evaluate($user, ['equipped_slots']);
    }

    public function unequip(User $user, string $slot): void
    {
        UserEquipment::where('user_id', $user->id)->where('slot', $slot)->delete();
        $this->clampToMax($user);
    }

    /** Trank benutzen: stats.heal (HP) bzw. stats.mana (Mana). */
    public function use(User $user, Item $item): string
    {
        if ($item->type !== 'potion') {
            throw new GameException('Das kann man nicht benutzen.');
        }

        return DB::transaction(function () use ($user, $item) {
            $this->take($user, $item);
            $stats = UserStats::lockForUpdate()->findOrFail($user->id);

            $heal = (float) ($item->stats['heal'] ?? 0);
            $mana = (float) ($item->stats['mana'] ?? 0);
            Progression::heal($stats, $heal);
            $stats->mp = min(Formulas::maxMp($stats), $stats->mp + $mana);
            $stats->save();

            return trim(($heal ? "+{$heal} HP " : '').($mana ? "+{$mana} Mana" : '')) ?: 'Benutzt.';
        });
    }

    public function hatch(User $user, Item $egg, Item $potion): UserPet
    {
        if ($egg->type !== 'egg' || $potion->type !== 'hatching_potion') {
            throw new GameException('Dazu brauchst du ein Ei und ein Schlüpfpulver.');
        }

        return DB::transaction(function () use ($user, $egg, $potion) {
            if (UserPet::where(['user_id' => $user->id, 'egg_item_id' => $egg->id, 'potion_item_id' => $potion->id])->exists()) {
                throw new GameException('Dieses Haustier hast du schon.');
            }

            $this->take($user, $egg);
            $this->take($user, $potion);

            app(FeedService::class)->post($user->family_id, $user, 'pet_hatched', '🐣', "{$user->name} hat ein Haustier ausgebrütet: ".self::petName($egg, $potion));

            $pet = UserPet::create([
                'user_id' => $user->id,
                'egg_item_id' => $egg->id,
                'potion_item_id' => $potion->id,
                'name' => self::petName($egg, $potion),
            ]);
            app(AchievementService::class)->evaluate($user, ['pets']);

            return $pet;
        });
    }

    public function feed(User $user, UserPet $pet, Item $food): UserPet
    {
        if ($pet->user_id !== $user->id) {
            throw new GameException('Das ist nicht dein Haustier.');
        }
        if ($food->type !== 'food') {
            throw new GameException('Das frisst dein Haustier nicht.');
        }
        if ($pet->is_mount) {
            throw new GameException("{$pet->name} ist schon ausgewachsen.");
        }

        return DB::transaction(function () use ($user, $pet, $food) {
            $this->take($user, $food);
            // Mit Stall im Familienhaus (§2.6) wächst das Tier etwas schneller
            $stable = $user->family_id && Room::where('type', 'stable')->whereHas('home', fn ($q) => $q->where('family_id', $user->family_id))->exists();
            $growth = config('game.pet_growth_per_food') + ($stable ? config('game.housing.stable_feed_bonus') : 0);
            $pet->growth = min(config('game.pet_growth_mount'), $pet->growth + $growth);
            $pet->is_mount = $pet->growth >= config('game.pet_growth_mount');
            $pet->save();
            if ($pet->is_mount) {
                app(AchievementService::class)->evaluate($user, ['mounts']);
            }

            return $pet;
        });
    }

    public function allocate(User $user, string $attribute): UserStats
    {
        if (! in_array($attribute, Attributes::KEYS, true)) {
            throw new GameException('Unbekanntes Attribut.');
        }

        return DB::transaction(function () use ($user, $attribute) {
            $stats = UserStats::lockForUpdate()->findOrFail($user->id);
            if ($stats->unspent_points < 1) {
                throw new GameException('Du hast keine Punkte zum Verteilen.');
            }
            $stats->unspent_points--;
            $stats->{$attribute}++;
            $stats->save();

            return $stats;
        });
    }

    /** „Feuer-Schlüpfpulver“ + „Wolfsei“ → „Feuer-Wolf“ */
    public static function petName(Item $egg, Item $potion): string
    {
        // „Wolfsei“ → Wolf, „Katzenei“ → Katze, „Drachenei“ → Drache (Fugen-s bzw. -n entfernen)
        $animal = preg_replace('/(s|n)?ei$/u', '', $egg->name);
        $color = preg_replace('/-?Schlüpfpulver$/u', '', $potion->name);

        return trim($color).'-'.trim($animal);
    }

    public function quantity(User $user, Item $item): int
    {
        return (int) UserItem::where('user_id', $user->id)->where('item_id', $item->id)->value('quantity');
    }

    private function add(User $user, Item $item, int $quantity): void
    {
        UserItem::firstOrCreate(['user_id' => $user->id, 'item_id' => $item->id])->increment('quantity', $quantity);
    }

    private function take(User $user, Item $item): void
    {
        $taken = UserItem::where('user_id', $user->id)->where('item_id', $item->id)->whereColumn('quantity', '>', 'master_quantity')->decrement('quantity');

        if ($taken === 0) {
            throw new GameException("Du hast kein „{$item->name}“ mehr.");
        }
    }

    private function clampToMax(User $user): void
    {
        $stats = UserStats::findOrFail($user->id);
        $stats->update(['mp' => min($stats->mp, Formulas::maxMp($stats))]);
    }
}
