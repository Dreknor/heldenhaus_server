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

    /**
     * Tierarten, deren Bild nur einen Kopf zeigt (Fluent hat für sie kein Ganzkörper-Bild) oder auf denen
     * niemand reiten kann. Als Reittier stehen sie groß neben dem Helden, statt ihn zu tragen.
     */
    public const NOT_RIDEABLE = [
        'bear', 'fox', 'frog', 'hamster', 'koala', 'lion', 'moose', 'panda', 'polar_bear', 'raccoon', 'unicorn', 'wolf', 'zebra',
        'bee', 'butterfly', 'crab', 'jellyfish', 'ladybug', 'octopus', 'sloth', 'snail', 'snowman', 'tropical_fish',
    ];

    public static function rideable(string $eggKey): bool
    {
        return ! in_array(str_replace('egg_', '', $eggKey), self::NOT_RIDEABLE, true);
    }

    /** @return array{main: string, belly: string}|null */
    public static function palette(?Item $potion): ?array
    {
        return $potion?->stats['palette'] ?? null;
    }
}
