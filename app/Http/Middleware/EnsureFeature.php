<?php

namespace App\Http\Middleware;

use App\Domain\Features\Features;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gesperrte Funktionen (§13.4) antworten in der API mit 403 {"error":"feature_locked","unlocks_at_level":N},
 * im Web mit einer Meldung auf der vorigen Seite.
 * Familienrelevante Routen bekommen diese Middleware nie.
 */
class EnsureFeature
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $user = $request->user();
        if ($user && ! Features::has($user, $feature)) {
            $message = 'Das schaltest du mit Level '.Features::levelFor($feature, $user).' frei.';
            // Web (Inertia): freundliche Meldung statt JSON-Fehlerseite
            if ($request->header('X-Inertia') || ! $request->expectsJson()) {
                return back()->withErrors(['game' => $message]);
            }

            return response()->json([
                'error' => 'feature_locked',
                'feature' => $feature,
                'unlocks_at_level' => Features::levelFor($feature, $user),
                'message' => $message,
            ], 403);
        }

        return $next($request);
    }
}
