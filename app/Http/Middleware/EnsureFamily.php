<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Ohne Familie geht es zuerst zur Einrichtung (Familie gründen oder per Code beitreten). */
class EnsureFamily
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() && $request->user()->family_id === null) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Du gehörst noch zu keiner Familie.'], 409)
                : redirect()->route('onboarding');
        }

        return $next($request);
    }
}
