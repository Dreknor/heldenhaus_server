<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Art\SceneBackgrounds;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Upload eines Hintergrund-Pakets im Online-Updater. Das ZIP kommt in Stücken
 * (Upload-Grenzen des Hostings), das Umwandeln läuft in Portionen, damit kein
 * Request an die PHP-Laufzeitgrenze stößt. Die Seite ruft beides nacheinander auf.
 */
class SceneBackgroundController extends Controller
{
    /** Größtes erlaubtes Paket */
    private const MAX_BYTES = 1024 * 1024 * 1024;

    private const DIR = 'scene-uploads';

    /** Stückgröße, die unter upload_max_filesize und post_max_size des Hostings bleibt (höchstens 8 MB). */
    public static function chunkBytes(): int
    {
        $limit = min(
            self::iniBytes(ini_get('upload_max_filesize')),
            self::iniBytes(ini_get('post_max_size')),
            8 * 1024 * 1024,
        );

        return max(256 * 1024, $limit - 64 * 1024);
    }

    private static function iniBytes(string|false $value): int
    {
        $value = trim((string) $value);
        if ($value === '' || $value === '0' || $value === '-1') {
            return PHP_INT_MAX;
        }
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }

    public function chunk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'upload' => ['required', 'uuid'],
            'offset' => ['required', 'integer', 'min:0'],
            'chunk' => ['required', 'file', 'max:10240'],
        ]);
        $path = $this->path($data['upload']);
        clearstatcache(true, $path);
        $size = file_exists($path) ? filesize($path) : 0;

        if ((int) $data['offset'] !== $size) {
            // Stück fehlt oder kam doppelt: die Seite setzt an der richtigen Stelle fort
            return response()->json(['received' => $size], 409);
        }
        if ($size + $data['chunk']->getSize() > self::MAX_BYTES) {
            @unlink($path);

            return response()->json(['message' => 'Das Paket ist größer als 1 GB.'], 422);
        }
        if ($size === 0) {
            // Abgebrochene frühere Uploads aufräumen
            collect(Storage::disk('local')->files(self::DIR))->each(fn ($f) => Storage::disk('local')->lastModified($f) < now()->subDay()->timestamp && Storage::disk('local')->delete($f));
            Storage::disk('local')->makeDirectory(self::DIR);
        }
        file_put_contents($path, file_get_contents($data['chunk']->getRealPath()), FILE_APPEND | LOCK_EX);

        return response()->json(['received' => $size + $data['chunk']->getSize()]);
    }

    public function import(Request $request): JsonResponse
    {
        $data = $request->validate([
            'upload' => ['required', 'uuid'],
            'from' => ['required', 'integer', 'min:0'],
            'title' => ['nullable', 'string', 'max:100'],
            'author' => ['nullable', 'string', 'max:100'],
            'url' => ['nullable', 'url', 'max:255'],
        ]);
        $path = $this->path($data['upload']);
        abort_unless(file_exists($path), 404);

        try {
            $batch = SceneBackgrounds::importBatch($path, [
                'title' => ($data['title'] ?? null) ?: SceneBackgrounds::DEFAULTS['title'],
                'author' => ($data['author'] ?? null) ?: SceneBackgrounds::DEFAULTS['author'],
                'url' => ($data['url'] ?? null) ?: SceneBackgrounds::DEFAULTS['url'],
            ], (int) $data['from'], 8);
        } catch (RuntimeException $e) {
            @unlink($path);

            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($batch['finished']) {
            @unlink($path);
            Log::info('Szenen-Hintergründe importiert', ['user_id' => $request->user()->id, 'count' => $batch['total']]);
        }

        return response()->json($batch + ['summary' => $batch['finished'] ? SceneBackgrounds::summary() : null]);
    }

    public function destroy(): RedirectResponse
    {
        SceneBackgrounds::remove();

        return back()->with('success', 'Die Szenen-Hintergründe wurden entfernt.');
    }

    private function path(string $upload): string
    {
        return Storage::disk('local')->path(self::DIR.'/'.strtolower($upload).'.zip');
    }
}
