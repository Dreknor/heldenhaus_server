<?php

namespace App\Domain\Inventory;

use App\Domain\Gameplay\GameException;
use App\Models\Item;
use App\Models\UserItem;

/**
 * Gegenstände im Inventar einer Person zählen, hinzufügen und entnehmen – innerhalb einer laufenden Transaktion.
 * Meisterhafte Stücke (master_quantity, Teil von quantity) werden nur bewegt, wenn man sie ausdrücklich wählt.
 */
class Stock
{
    public static function add(int $userId, int $itemId, int $quantity, bool $master = false): void
    {
        if ($quantity <= 0) {
            return;
        }
        $row = UserItem::firstOrCreate(['user_id' => $userId, 'item_id' => $itemId]);
        $row->increment('quantity', $quantity, $master ? ['master_quantity' => $row->master_quantity + $quantity] : []);
    }

    /** Wirft eine GameException, wenn nicht genug da ist. */
    public static function take(int $userId, Item $item, int $quantity, bool $master = false): void
    {
        $row = UserItem::where('user_id', $userId)->where('item_id', $item->id)->lockForUpdate()->first();
        $available = $row === null ? 0 : ($master ? $row->master_quantity : $row->quantity - $row->master_quantity);

        if ($available < $quantity) {
            throw new GameException($quantity === 1
                ? "Du hast kein „{$item->name}“".($master ? ' in meisterhafter Qualität' : '').' mehr.'
                : "Dafür brauchst du {$quantity}× „{$item->name}“ (du hast {$available}).");
        }

        $row->quantity -= $quantity;
        if ($master) {
            $row->master_quantity -= $quantity;
        }
        $row->save();
    }

    /** Mehrere Gegenstände nach Schlüssel entnehmen: ['wood' => 3, 'nails' => 1]. */
    public static function takeKeys(int $userId, array $amounts): void
    {
        $items = self::items(array_keys($amounts));
        foreach ($amounts as $key => $quantity) {
            self::take($userId, $items[$key], (int) $quantity);
        }
    }

    public static function addKeys(int $userId, array $amounts): void
    {
        $items = self::items(array_keys($amounts));
        foreach ($amounts as $key => $quantity) {
            self::add($userId, $items[$key]->id, (int) $quantity);
        }
    }

    /** Menge (ohne meisterhafte Stücke) je Schlüssel. */
    public static function counts(int $userId, array $keys): array
    {
        $rows = UserItem::join('items', 'items.id', '=', 'user_items.item_id')
            ->where('user_items.user_id', $userId)->whereIn('items.key', $keys)
            ->get(['items.key', 'user_items.quantity', 'user_items.master_quantity']);

        return collect($keys)->mapWithKeys(fn ($k) => [$k => 0])
            ->merge($rows->mapWithKeys(fn ($r) => [$r->key => $r->quantity - $r->master_quantity]))->all();
    }

    /** @return array<string, Item> */
    public static function items(array $keys): array
    {
        $items = Item::whereIn('key', $keys)->get()->keyBy('key');
        foreach ($keys as $key) {
            if (! isset($items[$key])) {
                throw new GameException("Den Gegenstand „{$key}“ gibt es nicht.");
            }
        }

        return $items->all();
    }
}
