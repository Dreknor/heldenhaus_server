<?php

use App\Domain\Gameplay\Attributes;
use App\Domain\Gameplay\GameException;
use App\Domain\Gameplay\ScoreTask;
use App\Domain\Inventory\InventoryService;
use App\Models\Item;
use App\Models\UserItem;
use App\Models\UserPet;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    [$this->family, $this->parent, $this->child] = makeFamily();
    $this->inventory = app(InventoryService::class);
    $this->sword = Item::create(['key' => 'sword', 'name' => 'Holzschwert', 'type' => 'equipment', 'slot' => 'weapon', 'price_gold' => 40, 'stats' => ['per' => 5]]);
});

function giveItem($user, Item $item, int $quantity = 1): void
{
    UserItem::firstOrCreate(['user_id' => $user->id, 'item_id' => $item->id])->increment('quantity', $quantity);
}

it('kauft im Gold-Shop nur mit genug Gold und Level', function () {
    expect(fn () => $this->inventory->buy($this->child, $this->sword))->toThrow(GameException::class, 'Gold');

    $this->child->stats()->update(['gold' => 50]);
    $this->inventory->buy($this->child, $this->sword);

    expect($this->child->stats()->first()->gold)->toBe(10.0)
        ->and($this->inventory->quantity($this->child, $this->sword))->toBe(1)
        ->and(fn () => $this->inventory->buy($this->child, $this->sword))->toThrow(GameException::class);

    $epic = Item::create(['key' => 'epic', 'name' => 'Episch', 'type' => 'food', 'price_gold' => 1, 'min_level' => 9]);
    expect(fn () => $this->inventory->buy($this->child, $epic))->toThrow(GameException::class, 'Level 9');
});

it('Ausrüstung wirkt auf Gold und Spürsinn', function () {
    giveItem($this->child, $this->sword);
    $this->inventory->equip($this->child, $this->sword);

    $result = app(ScoreTask::class)(assignmentOf(makeTask($this->child), $this->child), 'up');

    expect($result->completion->gold)->toBe(1.1); // +5 Spürsinn × 2 %

    $this->inventory->unequip($this->child, 'weapon');
    expect(Attributes::for($this->child->stats()->first())['per'])->toBe(0);
});

it('verteilt Attributpunkte', function () {
    $this->child->stats()->update(['unspent_points' => 1]);

    $this->inventory->allocate($this->child, 'con');

    expect($this->child->stats()->first()->con)->toBe(1)
        ->and(fn () => $this->inventory->allocate($this->child, 'con'))->toThrow(GameException::class);
});

it('Tränke heilen und werden verbraucht', function () {
    $potion = Item::create(['key' => 'heal', 'name' => 'Heiltrank', 'type' => 'potion', 'stats' => ['heal' => 15]]);
    giveItem($this->child, $potion);
    $this->child->stats()->update(['hp' => 30]);

    $this->inventory->use($this->child, $potion);

    expect($this->child->stats()->first()->hp)->toBe(45.0)
        ->and(fn () => $this->inventory->use($this->child, $potion))->toThrow(GameException::class);
});

it('brütet Haustiere aus und füttert sie zum Reittier', function () {
    $egg = Item::create(['key' => 'egg_wolf', 'name' => 'Wolfsei', 'type' => 'egg']);
    $fire = Item::create(['key' => 'potion_fire', 'name' => 'Feuer-Schlüpfpulver', 'type' => 'hatching_potion']);
    $apple = Item::create(['key' => 'apple', 'name' => 'Apfel', 'type' => 'food']);
    giveItem($this->child, $egg, 2);
    giveItem($this->child, $fire, 2);
    giveItem($this->child, $apple, 5);

    $pet = $this->inventory->hatch($this->child, $egg, $fire);
    expect($pet->name)->toBe('Feuer-Wolf')
        ->and(fn () => $this->inventory->hatch($this->child, $egg, $fire))->toThrow(GameException::class, 'schon');

    foreach (range(1, 5) as $i) {
        $pet = $this->inventory->feed($this->child, $pet, $apple);
    }

    expect($pet->is_mount)->toBeTrue()
        ->and(fn () => $this->inventory->feed($this->child, $pet, $apple))->toThrow(GameException::class);
});

it('lässt niemanden fremde Haustiere füttern', function () {
    [, , $other] = makeFamily();
    $egg = Item::create(['key' => 'e', 'name' => 'Katzenei', 'type' => 'egg']);
    $pot = Item::create(['key' => 'p', 'name' => 'Eis-Schlüpfpulver', 'type' => 'hatching_potion']);
    $food = Item::create(['key' => 'f', 'name' => 'Kuchen', 'type' => 'food']);
    $pet = UserPet::create(['user_id' => $other->id, 'egg_item_id' => $egg->id, 'potion_item_id' => $pot->id, 'name' => 'X']);
    giveItem($this->child, $food);

    expect(fn () => $this->inventory->feed($this->child, $pet, $food))->toThrow(GameException::class);
});

it('Inventar, Shop und Stall über Web und API', function () {
    $this->child->stats()->update(['gold' => 100]);

    $this->actingAs($this->child)->post("/inventory/{$this->sword->id}/buy")->assertSessionHasNoErrors();
    $this->post("/inventory/{$this->sword->id}/equip")->assertSessionHasNoErrors();
    $this->get('/inventory')->assertInertia(fn ($page) => $page->component('Inventory')->where('items.0.equipped', true));

    Sanctum::actingAs($this->child, ['app']);
    $this->getJson('/api/v1/hero')->assertJsonPath('equipment.weapon.name', 'Holzschwert')->assertJsonPath('attributes.per', 5);
    $this->postJson('/api/v1/inventory/'.$this->sword->id.'/buy')->assertStatus(422);
});
