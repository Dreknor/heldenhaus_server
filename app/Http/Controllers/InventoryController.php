<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\UserItem;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InventoryController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Inventory', [
            'items' => UserItem::with('item')
                ->where('user_id', $request->user()->id)
                ->where('quantity', '>', 0)
                ->get()
                ->sortBy(fn (UserItem $u) => [$u->item->type, $u->item->name])
                ->map(fn (UserItem $u) => [...$u->item->only(['id', 'name', 'description', 'type', 'rarity', 'icon', 'stats', 'slot']), 'quantity' => $u->quantity])
                ->values(),
            'types' => Item::TYPES,
            'rarities' => Item::RARITIES,
        ]);
    }
}
