<?php

use App\Domain\Gameplay\GameException;
use App\Http\Middleware\EnsureFamily;
use App\Http\Middleware\EnsureSupportedAppVersion;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RunDayCronIfDue;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->encryptCookies(except: ['appearance']);

        // Der Online-Updater muss auch im Wartungsmodus weiterlaufen
        $middleware->preventRequestsDuringMaintenance(except: ['admin/updates/*']);

        $middleware->alias([
            'family' => EnsureFamily::class,
            'day-cron' => RunDayCronIfDue::class,
            'ha-member' => \App\Http\Middleware\ActAsFamilyMember::class,
            'app-version' => EnsureSupportedAppVersion::class,
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Fachliche Spielfehler: JSON 422 für API, Validierungsfehler „game“ für Inertia
        $exceptions->render(function (GameException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return back()->withErrors(['game' => $e->getMessage()]);
        });
    })->create();
