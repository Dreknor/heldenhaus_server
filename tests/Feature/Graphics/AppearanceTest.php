<?php

use App\Domain\Inventory\InventoryService;
use App\Domain\Quests\QuestService;
use App\Models\Item;
use App\Models\UserItem;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    [$this->family, $this->parent, $this->child] = makeFamily();
    $this->look = ['skin' => '#c48654', 'hair' => 'curly', 'hair_color' => '#8e44ad', 'eyes' => 'wink', 'outfit' => '#27ae60', 'background' => '#bfdbfe'];
});

it('speichert den Avatar und liefert ihn mit der sichtbaren Ausrüstung aus', function () {
    $cap = Item::create(['key' => 'head_cap', 'name' => 'Mütze', 'type' => 'equipment', 'slot' => 'head', 'rarity' => 'rare']);
    UserItem::create(['user_id' => $this->child->id, 'item_id' => $cap->id, 'quantity' => 1]);
    app(InventoryService::class)->equip($this->child, $cap);

    $this->actingAs($this->child)->put('/hero/avatar', $this->look)->assertSessionHasNoErrors();

    expect($this->child->refresh()->avatar)->toBe($this->look);
    $this->get('/dashboard')->assertInertia(fn ($page) => $page
        ->where('auth.appearance.avatar.hair', 'curly')
        ->where('auth.appearance.equipment.head.key', 'head_cap')
        ->where('auth.appearance.equipment.head.rarity', 'rare'));
});

it('lehnt ungültige Avatar-Werte ab', function () {
    $this->actingAs($this->child)
        ->put('/hero/avatar', [...$this->look, 'hair' => 'irokese', 'skin' => 'red'])
        ->assertSessionHasErrors(['hair', 'skin']);
});

it('nimmt die neuen Toon-Head-Optionen an und lässt sie für ältere Clients weg', function () {
    $look = [...$this->look, 'hair' => 'undercut', 'hair_back' => 'shoulder', 'clothes' => 'openJacket', 'beard' => 'chin', 'eyes' => 'wide'];
    $this->actingAs($this->child)->put('/hero/avatar', $look)->assertSessionHasNoErrors();
    expect($this->child->refresh()->avatar)->toBe($look);

    $this->put('/hero/avatar', [...$this->look, 'beard' => 'goatee'])->assertSessionHasErrors(['beard']);
});

it('speichert den Avatar auch über die API', function () {
    Sanctum::actingAs($this->child, ['app']);

    $this->putJson('/api/v1/hero/avatar', $this->look)->assertOk()->assertJsonPath('avatar.eyes', 'wink');
});

it('liefert für jeden Boss eine eigene Grafik-Beschreibung', function () {
    $bosses = collect(QuestService::catalog())->where('type', 'boss');

    expect($bosses->every(fn ($q) => isset($q['art']['body'], $q['art']['color'])))->toBeTrue()
        ->and($bosses->pluck('art')->unique()->count())->toBe($bosses->count());

    $quest = app(QuestService::class)->start($this->parent, 'chaos_dragon', 'family');
    expect(QuestService::present($quest)['art']['body'])->toBe('dragon');
});

it('zeigt die Credits der verwendeten Grafiken öffentlich an', function () {
    $credits = json_decode(file_get_contents(resource_path('game-assets/credits.json')), true);

    foreach (collect($credits)->pluck('files')->flatten() as $file) {
        expect(base_path($file))->toBeFile();
    }
    expect(collect(glob(public_path('images/scene/*.webp')))->map(fn ($f) => 'public/images/scene/'.basename($f))->diff(collect($credits)->pluck('files')->flatten()))->toBeEmpty();

    $this->get('/credits')->assertOk()->assertInertia(fn ($page) => $page
        ->component('Credits')
        ->where('credits.0.author', 'Johan Melin')
        ->where('credits.0.license', 'CC BY 4.0'));
});
