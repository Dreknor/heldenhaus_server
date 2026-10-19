<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Updater\DeploymentEnvironment;
use App\Domain\Updater\ReleaseSource;
use App\Domain\Updater\UpdateRunner;
use App\Domain\Updater\UpdaterException;
use App\Http\Controllers\Controller;
use App\Models\SystemUpdate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class UpdateController extends Controller
{
    public function index(DeploymentEnvironment $env): Response
    {
        return Inertia::render('admin/Updates', [
            'version' => config('updater.version'),
            'channel' => config('updater.channel'),
            'managed' => $env->isManaged(),
            'configured' => (bool) (config('updater.github.repository') && config('updater.public_key')),
            'available' => Cache::get('updater:available'),
            'releases' => $env->isManaged() ? array_map(fn ($p) => ['path' => $p, 'version' => basename($p), 'current' => $p === $env->currentReleasePath()], $env->releases()) : [],
            'history' => SystemUpdate::latest()->limit(10)->get()->map(fn (SystemUpdate $u) => [
                'id' => $u->id,
                'from' => $u->from_version,
                'to' => $u->to_version,
                'status' => $u->status,
                'step' => $u->step,
                'log' => $u->log,
                'created_at' => $u->created_at->format('d.m.Y H:i'),
            ]),
            'steps' => UpdateRunner::STEPS,
        ]);
    }

    public function check(UpdateRunner $runner): RedirectResponse
    {
        try {
            $release = $runner->check();
        } catch (Throwable $e) {
            return back()->withErrors(['game' => 'Prüfung fehlgeschlagen: '.$e->getMessage()]);
        }

        Cache::put('updater:available', $release?->toArray(), now()->addDay());

        return back()->with('success', $release ? "Version {$release->version} ist verfügbar." : 'Heldenhaus ist aktuell.');
    }

    public function start(Request $request, UpdateRunner $runner, ReleaseSource $source): JsonResponse
    {
        $data = $request->validate(['version' => ['required', 'string']]);
        $release = $source->find($data['version']);
        abort_if($release === null, 404, 'Version nicht gefunden.');

        try {
            $update = $runner->start($release, $request->user());
        } catch (UpdaterException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->state($update));
    }

    /** Ein Schritt pro Anfrage – passt zu den Laufzeitgrenzen des Webhostings. */
    public function step(SystemUpdate $update, UpdateRunner $runner): JsonResponse
    {
        set_time_limit(280);

        return response()->json($this->state($runner->step($update)));
    }

    public function rollback(Request $request, UpdateRunner $runner): RedirectResponse
    {
        $data = $request->validate(['path' => ['required', 'string']]);

        try {
            $runner->rollbackTo($data['path']);
        } catch (Throwable $e) {
            return back()->withErrors(['game' => $e->getMessage()]);
        }

        return back()->with('success', 'Auf '.basename($data['path']).' zurückgeschaltet.');
    }

    private function state(SystemUpdate $u): array
    {
        return ['id' => $u->id, 'status' => $u->status, 'step' => $u->step, 'log' => $u->log, 'to' => $u->to_version];
    }
}
