<?php

namespace App\Domain\Art;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Gemalte Hintergründe für die Heldenszene, die nicht im Repository liegen dürfen
 * (Lizenz erlaubt Nutzung, aber keine Weitergabe). Sie werden auf dem eigenen Server
 * aus dem gekauften Paket importiert; ohne Import zeigt die Szene die Fluent-Grafiken.
 */
class SceneBackgrounds
{
    private const DIR = 'scenes';

    /** Innenräume passen nicht hinter den Helden im Freien. */
    private const INDOOR = '/Ballroom|Hallway|Kitchen|Prison|Staircase|Throne|Windows|Bedroom|Hearth|Interior|Library|Table|Tavern|Inn|Shop|Doorway|Cave/i';

    private const WIDTH = 1280;

    /** @return int Anzahl importierter Bilder */
    public static function import(string $zipPath, string $title, ?string $author, ?string $url, bool $indoor = false): int
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException("ZIP-Datei {$zipPath} lässt sich nicht öffnen.");
        }

        $disk = Storage::disk('public');
        $disk->deleteDirectory(self::DIR);
        $files = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = pathinfo($zip->getNameIndex($i), PATHINFO_FILENAME);
            if (! preg_match('/\.(png|jpe?g|webp)$/i', $zip->getNameIndex($i)) || str_starts_with($name, '.') || (! $indoor && preg_match(self::INDOOR, $name))) {
                continue;
            }
            $image = @imagecreatefromstring($zip->getFromIndex($i));
            if (! $image) {
                continue;
            }
            $scaled = imagescale($image, min(self::WIDTH, imagesx($image)));
            ob_start();
            imagewebp($scaled, null, 78);
            $file = Str::slug($name).'.webp';
            $disk->put(self::DIR.'/'.$file, ob_get_clean());
            $files[] = ['file' => $file, 'title' => $name];
        }
        $zip->close();

        usort($files, fn ($a, $b) => strnatcasecmp($a['title'], $b['title']));
        $disk->put(self::DIR.'/index.json', json_encode(compact('title', 'author', 'url', 'files'), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return count($files);
    }

    private static function index(): ?array
    {
        $disk = Storage::disk('public');

        return $disk->exists(self::DIR.'/index.json') ? json_decode($disk->get(self::DIR.'/index.json'), true) : null;
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
