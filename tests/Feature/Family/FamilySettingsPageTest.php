<?php

use App\Models\FamilyEventSetting;
use Inertia\Testing\AssertableInertia as Assert;

it('keeps the family page focused on the feed and serves settings separately', function () {
    [, $parent] = makeFamily();

    $this->actingAs($parent)->get('/family')->assertInertia(fn (Assert $page) => $page
        ->component('family/Index')
        ->has('feed')
        ->missing('members'));

    $this->get('/family/settings')->assertInertia(fn (Assert $page) => $page
        ->component('family/Settings')
        ->where('weatherSetting.source', 'none')
        ->where('weatherSetting.lat', null)
        ->where('weatherSetting.lon', null));
});

it('saves rounded Open-Meteo coordinates from family settings', function () {
    [$family, $parent] = makeFamily();

    $this->actingAs($parent)->put('/family/settings/weather', [
        'weather_source' => 'open_meteo',
        'weather_lat' => 51.512,
        'weather_lon' => 13.438,
    ])->assertSessionHasNoErrors();

    expect(FamilyEventSetting::findOrFail($family->id))
        ->weather_source->toBe('open_meteo')
        ->weather_lat->toBe(51.5)
        ->weather_lon->toBe(13.4);
});

it('requires coordinates to enable Open-Meteo and restricts settings to parents', function () {
    [, $parent, $child] = makeFamily();

    $this->actingAs($parent)->from('/family/settings')->put('/family/settings/weather', [
        'weather_source' => 'open_meteo',
        'weather_lat' => null,
        'weather_lon' => null,
    ])->assertSessionHasErrors(['weather_lat', 'weather_lon']);

    $this->actingAs($child)->get('/family/settings')->assertForbidden();
    $this->put('/family/settings/weather', ['weather_source' => 'none'])->assertForbidden();
});
