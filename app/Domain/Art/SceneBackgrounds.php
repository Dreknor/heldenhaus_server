<?php

namespace App\Domain\Art;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Gemalte Hintergründe für die Heldenszene, die nicht im Repository liegen dürfen
 * (Lizenz erlaubt Nutzung, aber keine Weitergabe). Sie werden auf dem eigenen Server
 * aus dem gekauften Paket importiert, per Konsole oder Upload im Online-Updater;
 * ohne Import zeigt die Szene die Fluent-Grafiken.
 */
class SceneBackgrounds
{
    private const DIR = 'scenes';

    /** Neue Bilder entstehen hier und ersetzen DIR erst, wenn alle fertig sind. */
    private const STAGING = 'scenes-import';

    /** Innenräume passen nicht hinter den Helden im Freien. */
    private const INDOOR = '/Ballroom|Hallway|Kitchen|Prison|Staircase|Throne|Windows|Bedroom|Hearth|Interior|Library|Table|Tavern|Inn|Shop|Doorway|Cave/i';

    private const WIDTH = 1280;

    public const DEFAULTS = ['title' => 'Fantasy Forest Backgrounds', 'author' => 'Lornn', 'url' => 'https://lornn.itch.io/fantasy-forest-backgrounds'];

    /** @return int Anzahl importierter Bilder */
    public static function import(string $zipPath, string $title, ?string $author, ?string $url, bool $indoor = false): int
    {
        $from = 0;
        do {
            $batch = self::importBatch($zipPath, compact('title', 'author', 'url', 'indoor'), $from, 25);
            $from = $batch['next'];
        } while (! $batch['finished']);

        return $batch['total'];
    }

    /**
     * Einen Teil der Bilder umwandeln (passt so in die Laufzeitgrenze eines Web-Requests).
     *
     * @param  array{title: string, author: ?string, url: ?string, indoor?: bool}  $meta
     * @return array{total: int, next: int, finished: bool}
     */
    public static function importBatch(string $zipPath, array $meta, int $from, int $limit): array
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Die Datei ist kein lesbares ZIP-Archiv.');
        }

        $entries = self::entries($zip, $meta['indoor'] ?? false);
        if (! $entries) {
            $zip->close();
            throw new RuntimeException('Im ZIP-Archiv sind keine passenden Bilder (PNG, JPG oder WebP).');
        }

        $disk = Storage::disk('public');
        if ($from === 0) {
            $disk->deleteDirectory(self::STAGING);
        }
        foreach (array_slice($entries, $from, $limit, true) as $index => $name) {
            $image = @imagecreatefromstring((string) $zip->getFromIndex($index));
            if (! $image) {
                continue;
            }
            $scaled = imagescale($image, min(self::WIDTH, imagesx($image)));
            ob_start();
            imagewebp($scaled, null, 78);
            $disk->put(self::STAGING.'/'.Str::slug($name).'.webp', ob_get_clean());
        }
        $zip->close();

        $next = min($from + $limit, count($entries));
        $finished = $next >= count($entries);
        if ($finished) {
            $files = collect($entries)->values()
                ->map(fn ($name) => ['file' => Str::slug($name).'.webp', 'title' => $name])
                ->filter(fn ($f) => $disk->exists(self::STAGING.'/'.$f['file']))
                ->sortBy('title', SORT_NATURAL | SORT_FLAG_CASE)->values()->all();
            $disk->put(self::STAGING.'/index.json', json_encode([
                'title' => $meta['title'], 'author' => $meta['author'], 'url' => $meta['url'], 'files' => $files,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $disk->deleteDirectory(self::DIR);
            File::moveDirectory($disk->path(self::STAGING), $disk->path(self::DIR));
        }

        return ['total' => count($entries), 'next' => $next, 'finished' => $finished];
    }

    /** @return array<int, string> ZIP-Index → Bildname, in fester Reihenfolge */
    private static function entries(ZipArchive $zip, bool $indoor): array
    {
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $path = $zip->getNameIndex($i);
            $name = pathinfo($path, PATHINFO_FILENAME);
            if (preg_match('/\.(png|jpe?g|webp)$/i', $path) && ! str_starts_with($name, '.') && ! str_contains($path, '__MACOSX')
                && ($indoor || ! preg_match(self::INDOOR, $name))) {
                $entries[$i] = $name;
            }
        }

        return $entries;
    }

    public static function remove(): void
    {
        Storage::disk('public')->deleteDirectory(self::DIR);
        Storage::disk('public')->deleteDirectory(self::STAGING);
    }

    private static function index(): ?array
    {
        $disk = Storage::disk('public');

        return $disk->exists(self::DIR.'/index.json') ? json_decode($disk->get(self::DIR.'/index.json'), true) : null;
    }

    /** Was gerade installiert ist (für den Online-Updater). */
    public static function summary(): ?array
    {
        $index = self::index();

        return ($index['files'] ?? null) ? ['title' => $index['title'], 'author' => $index['author'], 'count' => count($index['files'])] : null;
    }

    /** Hintergrund des Tages (wechselt täglich, je Person verschieden). */
    public static function forToday(int $seed): ?string
    {
        $files = self::index()['files'] ?? [];
        if (! $files) {
            return null;
        }

        // Pfad ohne Host, damit das Bild auch bei abweichender APP_URL lädt
        return parse_url(Storage::disk('public')->url(self::DIR.'/'.$files[(now()->dayOfYear + $seed * 7) % count($files)]['file']), PHP_URL_PATH);
    }

    /** Eintrag für die Credits-Seite, wenn Hintergründe importiert sind. */
    public static function credit(): ?array
    {
        $index = self::index();
        if (! ($index['files'] ?? null)) {
            return null;
        }

        return [
            'title' => 'Szenen-Hintergründe: '.$index['title'],
            'author' => $index['author'] ?? 'unbekannt',
            'author_url' => $index['url'] ?? '',
            'source_url' => $index['url'] ?? '',
            'license' => 'Nutzungslizenz des Anbieters',
            'license_url' => $index['url'] ?? '',
            'changes' => 'Auf diesem Server aus dem erworbenen Paket importiert, auf '.self::WIDTH.' Pixel Breite verkleinert und als WebP gespeichert. Nicht Teil des Heldenhaus-Quellcodes.',
        ];
    }
}
