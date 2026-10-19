<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Classes\ClassService;
use App\Domain\Inventory\InventoryService;
use App\Http\Controllers\Controller;
use App\Http\Presenters\CharacterSheet;
use App\Http\Presenters\GamePresenter;
use App\Models\Item;
use App\Models\User;
use App\Models\UserPet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CharacterController extends Controller
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly ClassService $classes,
    ) {}

    public function hero(Request $request): JsonResponse
    {
        return response()->json(CharacterSheet::hero($request->user()));
    }

    public function inventory(Request $request): JsonResponse
    {
        return response()->json(CharacterSheet::inventory($request->user()));
    }

    public function allocate(Request $request): JsonResponse
    {
        $stats = $this->inventory->allocate($request->user(), $request->validate(['attribute' => ['required', 'string']])['attribute']);

        return response()->json(['stats' => GamePresenter::stats($stats)]);
    }

    public function chooseClass(Request $request): JsonResponse
    {
        $stats = $this->classes->choose($request->user(), $request->validate(['class' => ['required', 'string']])['class']);

        return response()->json(['stats' => GamePresenter::stats($stats)]);
    }

    public function cast(Request $request): JsonResponse
    {
        $data = $request->validate(['skill' => ['required', 'string'], 'target_id' => ['nullable', 'integer']]);
        $target = isset($data['target_id']) ? User::findOrFail($data['target_id']) : null;

        return response()->json(['message' => $this->classes->cast($request->user(), $data['skill'], $target)]);
    }

    public function secondChance(Request $request): JsonResponse
    {
        $data = $request->validate(['assignment_id' => ['required', 'integer']]);

        return response()->json(['message' => $this->classes->secondChance($request->user(), (int) $data['assignment_id'])]);
    }

    public function buy(Request $request, Item $item): JsonResponse
    {
        return response()->json(['stats' => GamePresenter::stats($this->inventory->buy($request->user(), $item))]);
    }

    public function equip(Request $request, Item $item): JsonResponse
    {
        $this->inventory->equip($request->user(), $item);

        return response()->json(['ok' => true]);
    }

    public function unequip(Request $request): JsonResponse
    {
        $this->inventory->unequip($request->user(), $request->validate(['slot' => ['required', Rule::in(array_keys(Item::SLOTS))]])['slot']);

        return response()->json(['ok' => true]);
    }

    public function use(Request $request, Item $item): JsonResponse
    {
        return response()->json(['message' => $this->inventory->use($request->user(), $item)]);
    }

    public function hatch(Request $request): JsonResponse
    {
        $data = $request->validate(['egg_id' => ['required', 'integer'], 'potion_id' => ['required', 'integer']]);
        $pet = $this->inventory->hatch($request->user(), Item::findOrFail($data['egg_id']), Item::findOrFail($data['potion_id']));

        return response()->json(['id' => $pet->id, 'name' => $pet->name], 201);
    }

    public function feed(Request $request, UserPet $pet): JsonResponse
    {
        $data = $request->validate(['food_id' => ['required', 'integer']]);
        $pet = $this->inventory->feed($request->user(), $pet, Item::findOrFail($data['food_id']));

        return response()->json(['id' => $pet->id, 'growth' => $pet->growth, 'is_mount' => $pet->is_mount]);
    }
}
