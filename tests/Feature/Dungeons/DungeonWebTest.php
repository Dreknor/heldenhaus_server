<?php

beforeEach(function () {
    $this->artisan('game:content-sync')->assertSuccessful();
    [, $this->parent, $this->child] = makeFamily();

});

it('renders the family expedition and browser-ready inventory data', function () {
    $this->actingAs($this->child)
        ->get(route('dungeons.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Dungeons')
            ->where('familyDungeon.kind', 'family')
            ->where('soloDungeon', null)
            ->has('familyDungeon.rooms')
            ->has('familyDungeon.art.sprites')
            ->has('inventory.healingPotions')
            ->has('merchantOffers'));
});
