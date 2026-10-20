<?php

namespace App\Domain\Art;

use App\Models\Item;

/**
 * Haustier-Bilder (Fluent Emoji 3D, MIT, erzeugt mit tools/pets/build_pets.py) und die Farbe des Schlüpfpulvers.
 * Fehlt ein Bild, zeichnen Web und App die Tierart selbst.
 */
class PetArt
{
    public static function url(string $eggKey): ?string
    {
        $species = str_replace('egg_', '', $eggKey);

        return is_file(public_path("images/pets/{$species}.webp")) ? "/images/pets/{$species}.webp" : null;
    }

    /** @return array{main: string, belly: string}|null */
    public static function palette(?Item $potion): ?array
    {
        return $potion?->stats['palette'] ?? null;
    }
}
