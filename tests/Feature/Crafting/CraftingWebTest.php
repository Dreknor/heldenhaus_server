<?php

use App\Domain\Inventory\Stock;
use App\Domain\Social\AllianceService;
use App\Models\FamilyAlliance;
use App\Models\Item;
use App\Models\TradeOffer;

beforeEach(function () {
    $this->artisan('game:content-sync')->assertSuccessful();
    [$this->family, $this->parent, $this->child] = makeFamily();
});

it('renders the crafting and family exchange page', function () {
    $this->actingAs($this->child)->get(route('crafting.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Crafting')
            ->has('crafting.recipes')
            ->has('crafting.jobs')
            ->has('storage')
            ->has('members')
            ->has('alliances')
            ->has('trades'));
});

it('deposits materials and gifts items through the web actions', function () {
    $wood = Item::firstWhere('key', 'wood');
    $chair = Item::firstWhere('key', 'furn_chair');
    Stock::add($this->child->id, $wood->id, 5);
    Stock::add($this->child->id, $chair->id, 1);

    $this->actingAs($this->child)->post(route('crafting.storage.deposit'), [
        'item_id' => $wood->id,
        'quantity' => 3,
    ])->assertRedirect()->assertSessionHas('success');

    $this->post(route('crafting.gifts.store'), [
        'item_id' => $chair->id,
        'quantity' => 1,
        'to' => $this->parent->id,
    ])->assertRedirect()->assertSessionHas('success');

    expect(Stock::counts($this->child->id, ['wood', 'furn_chair']))->toBe(['wood' => 2, 'furn_chair' => 0])
        ->and(Stock::counts($this->parent->id, ['furn_chair'])['furn_chair'])->toBe(1);
});

it('creates and approves alliance offers from the web UI', function () {
    [, $otherParent] = makeFamily();
    $alliance = app(AllianceService::class)->join($otherParent, app(AllianceService::class)->invite($this->parent)->code);
    $wood = Item::firstWhere('key', 'wood');
    $ink = Item::firstWhere('key', 'ink');
    Stock::add($this->child->id, $wood->id, 3);
    Stock::add($otherParent->id, $ink->id, 2);

    $this->actingAs($this->child)->post(route('crafting.trades.store'), [
        'alliance_id' => $alliance->id,
        'offer' => [$wood->id => 2],
        'want' => [$ink->id => 1],
    ])->assertRedirect()->assertSessionHas('success');

    $trade = TradeOffer::firstOrFail();
    expect($trade->status)->toBe('awaiting_parent');

    $this->actingAs($this->parent)->post(route('crafting.trades.action', ['trade' => $trade->id, 'action' => 'approve']))
        ->assertRedirect()->assertSessionHas('success');
    expect($trade->refresh()->status)->toBe('open')
        ->and(FamilyAlliance::find($alliance->id)->status)->toBe('active');
});
