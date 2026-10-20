<?php

namespace App\Domain\Crafting;

use App\Domain\Gameplay\GameException;
use App\Domain\Inventory\Stock;
use App\Domain\Notifications\PushNotifier;
use App\Domain\Social\FeedService;
use App\Models\FamilyStorage;
use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Familienlager und Schenken innerhalb der Familie (Konzept §2.5). */
class StorageService
{
    public function __construct(private readonly FeedService $feed, private readonly PushNotifier $push) {}

    public function deposit(User $user, Item $item, int $quantity): FamilyStorage
    {
        $this->ensureMaterial($user, $item, $quantity);

        return DB::transaction(function () use ($user, $item, $quantity) {
            Stock::take($user->id, $item, $quantity);
            $row = FamilyStorage::firstOrCreate(['family_id' => $user->family_id, 'item_id' => $item->id]);
            $row->increment('quantity', $quantity);

            return $row;
        });
    }

    public function withdraw(User $user, Item $item, int $quantity): FamilyStorage
    {
        $this->ensureMaterial($user, $item, $quantity);

        return DB::transaction(function () use ($user, $item, $quantity) {
            $row = FamilyStorage::where('family_id', $user->family_id)->where('item_id', $item->id)->lockForUpdate()->first();
            if ($row === null || $row->quantity < $quantity) {
                throw new GameException("Im Familienlager ist nicht genug „{$item->name}“.");
            }
            $row->decrement('quantity', $quantity);
            Stock::add($user->id, $item->id, $quantity);

            return $row;
        });
    }

    /** Aus dem Lager entnehmen, ohne es ins Inventar zu legen (z. B. für Bauprojekte). */
    public static function consume(int $familyId, Item $item, int $quantity): void
    {
        $taken = FamilyStorage::where('family_id', $familyId)->where('item_id', $item->id)->where('quantity', '>=', $quantity)
            ->decrement('quantity', $quantity);
        if ($taken === 0) {
            throw new GameException("Im Familienlager ist nicht genug „{$item->name}“.");
        }
    }

    public function gift(User $from, User $to, Item $item, int $quantity, bool $master = false): void
    {
        if ($quantity < 1) {
            throw new GameException('Wie viel möchtest du verschenken?');
        }
        if ($from->id === $to->id || ! $from->sameFamilyAs($to)) {
            throw new GameException('Schenken geht nur an andere in deiner Familie.');
        }

        DB::transaction(function () use ($from, $to, $item, $quantity, $master) {
            Stock::take($from->id, $item, $quantity, $master);
            Stock::add($to->id, $item->id, $quantity, $master);
            $this->feed->post($from->family_id, $from, 'gift', '🎁', "{$from->name} hat {$to->name} {$quantity}× {$item->name} geschenkt.");
        });
        $this->push->notify($to, 'gift');
    }

    public static function present(int $familyId): array
    {
        return FamilyStorage::with('item')->where('family_id', $familyId)->where('quantity', '>', 0)->get()
            ->map(fn (FamilyStorage $s) => ['item_id' => $s->item_id, 'key' => $s->item->key, 'name' => $s->item->name, 'icon' => $s->item->icon, 'quantity' => $s->quantity])
            ->sortBy('name')->values()->all();
    }

    private function ensureMaterial(User $user, Item $item, int $quantity): void
    {
        if (! $user->family_id) {
            throw new GameException('Das Familienlager gibt es nur in einer Familie.');
        }
        if ($item->type !== 'material') {
            throw new GameException('Ins Familienlager kommen nur Materialien.');
        }
        if ($quantity < 1) {
            throw new GameException('Wie viel denn?');
        }
    }
}
