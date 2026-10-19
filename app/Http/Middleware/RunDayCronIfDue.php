<?php

namespace App\Http\Middleware;

use App\Domain\Gameplay\RunDayCron;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * „Lazy“ Tageswechsel beim ersten Aufruf des Tages. Gibt es offene Dailies vom Vortag,
 * wartet er auf den Dialog „Gestern noch erledigt?“ (POST /cron).
 */
class RunDayCronIfDue
{
    public function __construct(private readonly RunDayCron $cron) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->stats !== null) {
            if ($user->stats->last_cron_on === null) {
                ($this->cron)($user);
            } elseif (RunDayCron::isPending($user) && RunDayCron::openFromLastDay($user)->isEmpty()) {
                ($this->cron)($user);
                $user->unsetRelation('stats');
            }
        }

        return $next($request);
    }
}
