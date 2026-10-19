<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Inventory\DropService;
use App\Http\Controllers\Controller;
use App\Models\GameSetting;
use App\Models\Item;
use App\Models\UserItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Admin-Backend: Items anlegen, bearbeiten und Drop-Wahrscheinlichkeiten einstellen. */
class ItemController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/Items', [
            'items' => Item::orderBy('type')->orderBy('name')->get()->map(fn (Item $item) => [
                ...$item->toArray(),
                'owned' => (int) UserItem::where('item_id', $item->id)->sum('quantity'),
            ]),
            'settings' => DropService::settings(),
            'types' => Item::TYPES,
            'slots' => Item::SLOTS,
            'rarities' => Item::RARITIES,
            'categories' => config('game.categories'),
            'difficulties' => config('game.difficulties'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Item::create($this->validated($request));

        return back()->with('success', 'Item angelegt.');
    }

    public function update(Request $request, Item $item): RedirectResponse
    {
        $item->update($this->validated($request, $item));

        return back()->with('success', 'Item gespeichert.');
    }

    /** Items, die schon jemand besitzt, werden nur deaktiviert. */
    public function destroy(Item $item): RedirectResponse
    {
        if (UserItem::where('item_id', $item->id)->where('quantity', '>', 0)->exists()) {
            $item->update(['active' => false, 'drop_weight' => 0]);

            return back()->with('success', 'Item ist schon im Besitz von Spielern und wurde deaktiviert.');
        }

        $item->delete();

        return back()->with('success', 'Item gelöscht.');
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'base_chance' => ['required', 'numeric', 'between:0,0.95'],
            'daily_limit' => ['required', 'integer', 'between:0,100'],
            'per_bonus_per_point' => ['required', 'numeric', 'between:0,0.2'],
        ], [], ['base_chance' => 'Grundchance', 'daily_limit' => 'Tageslimit']);

        GameSetting::put('drop.base_chance', (float) $data['base_chance']);
        GameSetting::put('drop.daily_limit', (int) $data['daily_limit']);
        GameSetting::put('drop.per_bonus_per_point', (float) $data['per_bonus_per_point']);

        return back()->with('success', 'Drop-Einstellungen gespeichert.');
    }

    private function validated(Request $request, ?Item $item = null): array
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9_]+$/', Rule::unique('items', 'key')->ignore($item?->id)],
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:500'],
            'type' => ['required', Rule::in(array_keys(Item::TYPES))],
            'slot' => ['nullable', 'required_if:type,equipment', Rule::in(array_keys(Item::SLOTS))],
            'rarity' => ['required', Rule::in(array_keys(Item::RARITIES))],
            'icon' => ['required', 'string', 'max:16'],
            'stats' => ['nullable', 'array'],
            'stats.*' => ['integer', 'between:0,50'],
            'price_gold' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'drop_weight' => ['required', 'integer', 'min:0', 'max:10000'],
            'drop_categories' => ['nullable', 'array'],
            'drop_categories.*' => [Rule::in(array_keys(config('game.categories')))],
            'min_level' => ['required', 'integer', 'min:1', 'max:999'],
            'active' => ['boolean'],
        ], [], ['key' => 'Schlüssel', 'drop_weight' => 'Drop-Gewicht', 'slot' => 'Slot']);

        $data['stats'] = array_filter($data['stats'] ?? [], fn ($v, $k) => in_array($k, ['str', 'int', 'con', 'per'], true) && $v > 0, ARRAY_FILTER_USE_BOTH) ?: null;
        $data['drop_categories'] = ($data['drop_categories'] ?? []) ?: null;
        $data['slot'] = $data['type'] === 'equipment' ? $data['slot'] : null;

        return $data;
    }
}
