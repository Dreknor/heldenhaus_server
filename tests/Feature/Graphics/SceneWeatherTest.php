<?php

use App\Models\FamilyEventSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

it('shows the current Open-Meteo weather in the dashboard scene when enabled', function () {
    [$family, , $child] = makeFamily();
    FamilyEventSetting::create([
        'family_id' => $family->id,
        'weather_source' => 'open_meteo',
        'weather_lat' => 51.5,
        'weather_lon' => 13.4,
    ]);
    Cache::flush();
    Http::fake([
        '*' => Http::response([
            'current' => ['weather_code' => 61, 'is_day' => 1],
        ]),
    ]);

    $this->actingAs($child)->get('/dashboard')
        ->assertInertia(fn ($page) => $page
            ->where('sceneWeather.type', 'rain')
            ->where('sceneWeather.label', 'Regen')
            ->where('sceneWeather.is_day', true));

    Http::assertSent(fn ($request) => $request['latitude'] === 51.5
        && $request['longitude'] === 13.4
        && $request['current'] === 'weather_code,is_day');
});

it('does not request weather when the family has not enabled Open-Meteo', function () {
    [, , $child] = makeFamily();
    Http::fake();

    $this->actingAs($child)->get('/dashboard')->assertOk();

    Http::assertNothingSent();
});
