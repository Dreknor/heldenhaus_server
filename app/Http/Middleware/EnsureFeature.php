<?php

namespace App\Http\Middleware;

use App\Domain\Features\Features;
use App\Http\Presenters\FeatureView;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gesperrte Funktionen (§13.4) antworten in der API mit 403 {"error":"feature_locked","unlocks_at_level":N},
 * im Web bei Aktionen mit einer Meldung auf der vorigen Seite und bei Seiten (GET) mit einer freundlichen
 * Vorschau „Schaltest du mit Level X frei“, ohne die Daten der Funktion zu laden.
 * Mehrere Schlüssel (feature:a,b): offen, sobald einer davon frei ist.
 * Familienrelevante Routen bekommen diese Middleware nie.
 */
class EnsureFeature
{
    public function handle(Request $request, Closure $next, string ...$features): Response
    {
        $user = $request->user();
        if ($user === null || collect($features)->contains(fn (string $f) => Features::has($user, $f))) {
            return $next($request);
        }

        $feature = collect($features)->sortBy(fn (string $f) => Features::levelFor($f, $user))->first();
        $level = Features::levelFor($feature, $user);
        $message = Features::state($user, $feature) === 'deferred'
            ? 'Das schalten deine Eltern etwas später frei.'
            : "Das schaltest du mit Level {$level} frei.";

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json([
                'error' => 'feature_locked',
                'feature' => $feature,
                'unlocks_at_level' => $level,
                'message' => $message,
            ], 403);
        }

        // Web (Inertia): Seiten zeigen eine Vorschau, Aktionen eine freundliche Meldung
        if ($request->isMethod('GET')) {
            return Inertia::render('features/Locked', FeatureView::preview($user, $features))->toResponse($request);
        }

        return back()->withErrors(['game' => $message]);
    }
}
