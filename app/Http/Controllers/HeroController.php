<?php

namespace App\Http\Controllers;

use App\Domain\Art\SceneBackgrounds;
use App\Domain\Events\WeatherSource;
use App\Domain\Gameplay\RunDayCron;
use App\Http\Presenters\HeroBoard;
use App\Http\Presenters\HubSummary;
use App\Models\DayLog;
use App\Models\FamilyEventSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HeroController extends Controller
{
    public function index(Request $request, WeatherSource $weather): Response
    {
        $user = $request->user();
        $sceneWeather = null;
        if ($user->family_id !== null) {
            $settings = FamilyEventSetting::for($user->family_id);
            if ($settings->weather_source === 'open_meteo' && $settings->weather_lat !== null && $settings->weather_lon !== null) {
                $sceneWeather = $weather->current($settings->weather_lat, $settings->weather_lon, $user->family?->timezone ?? $user->timezone);
            }
        }

        return Inertia::render('Dashboard', [
            ...HeroBoard::for($user),
            'focusMembers' => $user->family?->members()
                ->where('id', '!=', $user->id)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn ($member) => ['id' => $member->id, 'name' => $member->name])
                ->values() ?? collect(),
            'categories' => config('game.categories'),
            'difficulties' => config('game.difficulties'),
            'sceneBackground' => SceneBackgrounds::forToday($user->id),
            'sceneWeather' => $sceneWeather,
            'hub' => HubSummary::for($user),
        ]);
    }

    public function runCron(Request $request, RunDayCron $cron): RedirectResponse
    {
        $data = $request->validate([
            'done' => ['array'],
            'done.*' => ['integer'],
        ]);

        $log = $cron($request->user(), null, $data['done'] ?? []);

        return to_route('dashboard')->with('success', self::cronMessage($log));
    }

    public static function cronMessage(?DayLog $log): string
    {
        return match (true) {
            $log === null => 'Ein neuer Tag beginnt!',
            $log->knocked_out => 'Uff – dein Held war K.O. und hat sich ausgeruht. Heute geht\'s neu los!',
            $log->damage > 0 => "Neuer Tag! Verpasste Aufgaben kosteten {$log->damage} HP.",
            default => 'Neuer Tag – gestern lief super!',
        };
    }
}
