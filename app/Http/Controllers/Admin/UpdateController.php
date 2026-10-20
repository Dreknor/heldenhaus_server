<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Art\SceneBackgrounds;
use App\Domain\Updater\UpdaterException;
use App\Domain\Updater\UpdateService;
use App\Http\Controllers\Controller;
use Closure;
use Illuminate\Foundation\Http\MaintenanceModeBypassCookie;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Oberfläche des Online-Updaters. Die Ausführung selbst übernimmt der
 * Scheduler (updater:run --if-requested), damit das Update nicht an die
 * Laufzeit- und Rechtegrenzen des Webservers gebunden ist.
 */
class UpdateController extends Controller implements HasMiddleware
{
    public function __construct(private readonly UpdateService $updater) {}

    public static function middleware(): array
    {
        return [
            new Middleware(function (Request $request, Closure $next) {
                abort_unless(config('updater.enabled'), 404);

                return $next($request);
            }),
        ];
    }

    public function index(): Response
    {
        return Inertia::render('admin/Updates', [
            'check' => $this->updater->lastCheck(),
            'state' => $this->updater->publicState(),
            'log' => $this->updater->logTail(),
            'maintenance' => app()->isDownForMaintenance(),
            'backgrounds' => SceneBackgrounds::summary(),
            'uploadChunkBytes' => SceneBackgroundController::chunkBytes(),
        ]);
    }

    public function status(): JsonResponse
    {
        return response()->json([
            'state' => $this->updater->publicState(),
            'log' => $this->updater->logTail(),
            'maintenance' => app()->isDownForMaintenance(),
        ]);
    }

    public function check(): RedirectResponse
    {
        $result = $this->updater->check();

        if ($result['error']) {
            return back()->withErrors(['game' => 'Prüfung fehlgeschlagen: '.$result['error']]);
        }
        if ($result['fetch_error']) {
            return back()->withErrors(['game' => 'Das Repository konnte nicht abgefragt werden (Prüfung erfolgt zusätzlich per Scheduler).']);
        }

        return back()->with('success', $result['behind'] > 0
            ? $result['behind'].' neue(r) Commit(s) verfügbar.'
            : 'Heldenhaus ist aktuell.');
    }

    public function start(Request $request): RedirectResponse
    {
        // Läuft Heldenhaus bereits im Wartungsmodus (z. B. nach einem fehlgeschlagenen
        // Update), muss der Bypass zum bestehenden Geheimnis passen.
        $maintenance = app()->maintenanceMode();
        $secret = ($maintenance->active() ? ($maintenance->data()['secret'] ?? null) : null) ?: Str::random(40);

        try {
            $this->updater->request($request->user()->name, $request->boolean('full'), $secret);
        } catch (UpdaterException $e) {
            return back()->withErrors(['game' => $e->getMessage()]);
        }

        Log::info('Online-Update angefordert', ['user_id' => $request->user()->id, 'full' => $request->boolean('full')]);

        // Bypass-Cookie: Der Admin kann den Fortschritt auch im Wartungsmodus verfolgen.
        return back()->with('success', 'Das Update wurde angefordert und startet innerhalb einer Minute.')
            ->withCookie(MaintenanceModeBypassCookie::create($secret));
    }

    public function cancel(): RedirectResponse
    {
        return $this->updater->cancelRequest()
            ? back()->with('success', 'Die Update-Anforderung wurde zurückgenommen.')
            : back()->withErrors(['game' => 'Es ist kein Update angefordert (oder es läuft bereits).']);
    }

    public function up(Request $request): RedirectResponse
    {
        if ($this->updater->isRunning()) {
            return back()->withErrors(['game' => 'Während eines laufenden Updates kann der Wartungsmodus nicht beendet werden.']);
        }

        Artisan::call('up');
        Log::warning('Wartungsmodus manuell über den Online-Updater beendet', ['user_id' => $request->user()->id]);

        return back()->with('success', 'Der Wartungsmodus wurde beendet.');
    }
}
