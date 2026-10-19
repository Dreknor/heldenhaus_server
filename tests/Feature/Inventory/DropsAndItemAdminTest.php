<?php

use App\Domain\Gameplay\ScoreTask;
use App\Domain\Inventory\DropService;
use App\Models\GameSetting;
use App\Models\Item;
use App\Models\UserItem;
use Random\Engine;
use Random\Randomizer;

/** Zufall, der immer „ganz unten“ landet: jede Chance trifft, gewählt wird das erste Item. */
function luckyDrops(): void
{
    app()->instance(DropService::class, new DropService(new Randomizer(new class implements Engine
    {
        public function generate(): string
        {
            return "\0\0\0\0\0\0\0\0";
        }
    })));
}

beforeEach(function () {
    [$this->family, $this->parent, $this->child] = makeFamily();
});

it('berechnet die Fund-Chance nach Schwierigkeit und Spürsinn', function () {
    $settings = ['base_chance' => 0.2, 'daily_limit' => 5, 'per_bonus_per_point' => 0.05];

    expect(DropService::chanceFor(1, 0, $settings))->toBe(0.2)
        ->and(DropService::chanceFor(2, 0, $settings))->toEqualWithDelta(0.3, 1e-9)
        ->and(DropService::chanceFor(1, 4, $settings))->toEqualWithDelta(0.24, 1e-9)
        ->and(DropService::chanceFor(2, 100, ['base_chance' => 0.9] + $settings))->toBe(0.95);
});

it('filtert den Drop-Pool nach Kategorie, Level, Gewicht und Aktivität', function () {
    Item::create(['key' => 'a', 'name' => 'Überall', 'type' => 'food', 'drop_weight' => 5]);
    Item::create(['key' => 'b', 'name' => 'Nur Schule', 'type' => 'material', 'drop_weight' => 5, 'drop_categories' => ['school']]);
    Item::create(['key' => 'c', 'name' => 'Ab Level 10', 'type' => 'egg', 'drop_weight' => 5, 'min_level' => 10]);
    Item::create(['key' => 'd', 'name' => 'Nie', 'type' => 'egg', 'drop_weight' => 0]);
    Item::create(['key' => 'e', 'name' => 'Inaktiv', 'type' => 'egg', 'drop_weight' => 5, 'active' => false]);

    expect(DropService::pool('household', 1)->pluck('key')->all())->toBe(['a'])
        ->and(DropService::pool('school', 12)->pluck('key')->sort()->values()->all())->toBe(['a', 'b', 'c']);
});

it('vergibt Funde beim Erledigen und nimmt sie beim Rückgängigmachen wieder weg', function () {
    luckyDrops();
    $egg = Item::create(['key' => 'egg', 'name' => 'Drachenei', 'type' => 'egg', 'drop_weight' => 10]);
    $task = makeTask($this->child, ['type' => 'todo']);
    $score = app(ScoreTask::class);

    $result = $score(assignmentOf($task, $this->child), 'up');

    expect($result->drop?->id)->toBe($egg->id)
        ->and($result->toArray()['drop']['name'])->toBe('Drachenei')
        ->and(UserItem::where('user_id', $this->child->id)->value('quantity'))->toBe(1);

    $score(assignmentOf($task, $this->child), 'down');

    expect(UserItem::where('user_id', $this->child->id)->value('quantity'))->toBe(0);
});

it('hält das Tageslimit für Funde ein', function () {
    luckyDrops();
    GameSetting::put('drop.daily_limit', 2);
    Item::create(['key' => 'apple', 'name' => 'Apfel', 'type' => 'food', 'drop_weight' => 1]);
    $habit = makeTask($this->child, ['type' => 'habit']);
    $score = app(ScoreTask::class);

    foreach (range(1, 4) as $i) {
        $score(assignmentOf($habit, $this->child), 'up');
    }

    expect(UserItem::where('user_id', $this->child->id)->value('quantity'))->toBe(2);
});

it('lässt keine Nicht-Admins in die Item-Verwaltung', function () {
    $this->actingAs($this->parent)->get('/admin/items')->assertForbidden();
    $this->post('/admin/items', ['key' => 'x'])->assertForbidden();
});

it('erlaubt Admins Items anzulegen, zu ändern und Wahrscheinlichkeiten zu setzen', function () {
    $this->parent->forceFill(['is_admin' => true])->save();
    $this->actingAs($this->parent);

    $this->post('/admin/items', [
        'key' => 'sword_of_chores', 'name' => 'Schwert der Ordnung', 'type' => 'equipment', 'slot' => 'weapon',
        'rarity' => 'epic', 'icon' => '⚔️', 'stats' => ['str' => 3, 'int' => 0], 'price_gold' => 200,
        'drop_weight' => 2, 'drop_categories' => ['household'], 'min_level' => 5, 'active' => true,
    ])->assertSessionHasNoErrors();

    $item = Item::firstWhere('key', 'sword_of_chores');
    expect($item->stats)->toBe(['str' => 3])->and($item->drop_categories)->toBe(['household']);

    $this->put("/admin/items/{$item->id}", [...$item->only(['key', 'name', 'type', 'slot', 'rarity', 'icon', 'min_level']), 'drop_weight' => 50])
        ->assertSessionHasNoErrors();
    expect($item->refresh()->drop_weight)->toBe(50);

    $this->put('/admin/drop-settings', ['base_chance' => 0.4, 'daily_limit' => 8, 'per_bonus_per_point' => 0.01])->assertSessionHasNoErrors();
    expect(DropService::settings())->toBe(['base_chance' => 0.4, 'daily_limit' => 8, 'per_bonus_per_point' => 0.01]);

    $this->get('/admin/items')->assertOk();
});

it('prüft Item-Eingaben', function () {
    $this->parent->forceFill(['is_admin' => true])->save();
    Item::create(['key' => 'taken', 'name' => 'X', 'type' => 'food']);

    $this->actingAs($this->parent)->post('/admin/items', [
        'key' => 'taken', 'name' => '', 'type' => 'equipment', 'rarity' => 'mythic', 'icon' => '?', 'drop_weight' => -1, 'min_level' => 1,
    ])->assertSessionHasErrors(['key', 'name', 'slot', 'rarity', 'drop_weight']);
});

it('deaktiviert Items im Spielerbesitz statt sie zu löschen', function () {
    $this->parent->forceFill(['is_admin' => true])->save();
    $owned = Item::create(['key' => 'owned', 'name' => 'Besessen', 'type' => 'food', 'drop_weight' => 5]);
    $unused = Item::create(['key' => 'unused', 'name' => 'Unbenutzt', 'type' => 'food']);
    UserItem::create(['user_id' => $this->child->id, 'item_id' => $owned->id, 'quantity' => 2]);

    $this->actingAs($this->parent)->delete("/admin/items/{$owned->id}");
    $this->delete("/admin/items/{$unused->id}");

    expect($owned->refresh()->active)->toBeFalse()
        ->and($owned->drop_weight)->toBe(0)
        ->and(Item::find($unused->id))->toBeNull();
});

it('übernimmt Startinhalte aus JSON, ohne Admin-Änderungen zu überschreiben', function () {
    $this->artisan('game:content-sync')->assertSuccessful();
    Item::firstWhere('key', 'egg_wolf')->update(['drop_weight' => 99]);

    $this->artisan('game:content-sync')->assertSuccessful();

    expect(Item::count())->toBeGreaterThan(10)
        ->and(Item::firstWhere('key', 'egg_wolf')->drop_weight)->toBe(99);
});

it('zeigt das Inventar der eigenen Person', function () {
    $item = Item::create(['key' => 'apple', 'name' => 'Apfel', 'type' => 'food']);
    UserItem::create(['user_id' => $this->child->id, 'item_id' => $item->id, 'quantity' => 3]);

    $this->actingAs($this->child)->get('/inventory')
        ->assertInertia(fn ($page) => $page->component('Inventory')->where('items.0.quantity', 3));
});
