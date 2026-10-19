<?php

namespace App\Http\Controllers;

use App\Domain\Classes\ClassCatalog;
use App\Domain\Classes\ClassService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Quests\BossService;
use App\Http\Presenters\CharacterSheet;
use App\Models\Item;
use App\Models\User;
use App\Models\UserPet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Held (Attribute, Klasse, Fähigkeiten, Ausrüstung), Inventar/Shop/Stall und Familien-Boss im Web. */
class CharacterController extends Controller
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly ClassService $classes,
    ) {}

    public function hero(Request $request): Response
    {
        return Inertia::render('Hero', CharacterSheet::hero($request->user()));
    }

    public function inventory(Request $request): Response
    {
        return Inertia::render('Inventory', CharacterSheet::inventory($request->user()));
    }

    public function allocate(Request $request): RedirectResponse
    {
        $this->inventory->allocate($request->user(), $request->validate(['attribute' => ['required', 'string']])['attribute']);

        return back();
    }

    public function chooseClass(Request $request): RedirectResponse
    {
        $stats = $this->classes->choose($request->user(), $request->validate(['class' => ['required', 'string']])['class']);

        return back()->with('success', 'Du bist jetzt '.ClassCatalog::get($stats->class_key)['name'].'!');
    }

    public function cast(Request $request): RedirectResponse
    {
        $data = $request->validate(['skill' => ['required', 'string'], 'target_id' => ['nullable', 'integer']]);
        $target = isset($data['target_id']) ? User::findOrFail($data['target_id']) : null;

        return back()->with('success', $this->classes->cast($request->user(), $data['skill'], $target));
    }

    public function secondChance(Request $request): RedirectResponse
    {
        $data = $request->validate(['assignment_id' => ['required', 'integer']]);

        return back()->with('success', $this->classes->secondChance($request->user(), (int) $data['assignment_id']));
    }

    public function buy(Request $request, Item $item): RedirectResponse
    {
        $this->inventory->buy($request->user(), $item);

        return back()->with('success', "Gekauft: {$item->name}");
    }

    public function equip(Request $request, Item $item): RedirectResponse
    {
        $this->inventory->equip($request->user(), $item);

        return back()->with('success', "{$item->name} angelegt.");
    }

    public function unequip(Request $request): RedirectResponse
    {
        $this->inventory->unequip($request->user(), $request->validate(['slot' => ['required', Rule::in(array_keys(Item::SLOTS))]])['slot']);

        return back();
    }

    public function use(Request $request, Item $item): RedirectResponse
    {
        return back()->with('success', $this->inventory->use($request->user(), $item));
    }

    public function hatch(Request $request): RedirectResponse
    {
        $data = $request->validate(['egg_id' => ['required', 'integer'], 'potion_id' => ['required', 'integer']]);
        $pet = $this->inventory->hatch($request->user(), Item::findOrFail($data['egg_id']), Item::findOrFail($data['potion_id']));

        return back()->with('success', "Geschlüpft: {$pet->name}! 🐣");
    }

    public function feed(Request $request, UserPet $pet): RedirectResponse
    {
        $data = $request->validate(['food_id' => ['required', 'integer']]);
        $pet = $this->inventory->feed($request->user(), $pet, Item::findOrFail($data['food_id']));

        return back()->with('success', $pet->is_mount ? "{$pet->name} ist ausgewachsen – jetzt ein Reittier!" : "{$pet->name} hat es geschmeckt.");
    }

    public function startBoss(Request $request, BossService $boss): RedirectResponse
    {
        abort_unless($request->user()->isParent(), 403);
        $data = $request->validate(['boss' => ['required', 'string'], 'reward_text' => ['nullable', 'string', 'max:120']]);
        $quest = $boss->start($request->user(), $data['boss'], $data['reward_text'] ?? null);

        return back()->with('success', "{$quest->name} greift an! Jede erledigte Aufgabe trifft ihn.");
    }
}
