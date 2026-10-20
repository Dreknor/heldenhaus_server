<?php

namespace App\Http\Controllers;

use App\Domain\Art\SceneBackgrounds;
use App\Domain\Gameplay\RunDayCron;
use App\Http\Presenters\HeroBoard;
use App\Models\DayLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HeroController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Dashboard', [
            ...HeroBoard::for($request->user()),
            'categories' => config('game.categories'),
            'difficulties' => config('game.difficulties'),
            'sceneBackground' => SceneBackgrounds::forToday($request->user()->id),
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
