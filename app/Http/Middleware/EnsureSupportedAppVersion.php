<?php

namespace App\Http\Middleware;

use App\Models\AppRelease;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pflicht-Update: Zu alte App-Builds bekommen 426 und zeigen nur noch den Update-Bildschirm.
 * Die App sendet ihren Build im Header X-App-Build.
 */
class EnsureSupportedAppVersion
{
    public function handle(Request $request, Closure $next): Response
    {
        $build = $request->header('X-App-Build');

        if ($build !== null && ctype_digit($build)) {
            $min = Cache::remember('app:min_supported', 300, fn () => AppRelease::minSupportedCode());

            if ((int) $build < $min) {
                return response()->json([
                    'message' => 'Bitte aktualisiere die Heldenhaus-App.',
                    'min_supported' => $min,
                ], 426);
            }

            $token = $request->user('sanctum')?->currentAccessToken();
            if ($token instanceof PersonalAccessToken && $token->exists && $token->app_build !== (int) $build) {
                $token->forceFill(['app_build' => (int) $build])->save();
            }
        }

        return $next($request);
    }
}
