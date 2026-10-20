<?php

namespace App\Domain\Crafting;

use App\Domain\Gameplay\GameException;

/** Rezepte, Bauprojekte und Pflanzen aus database/content (Konzept §2.5, §2.6). */
class Catalog
{
    /** @var array<string, array<string, array>> */
    private static array $cache = [];

    public static function recipes(): array
    {
        return self::load('recipes');
    }

    public static function recipe(string $key): array
    {
        return self::recipes()[$key] ?? throw new GameException('Dieses Rezept gibt es nicht.');
    }

    public static function projects(): array
    {
        return self::load('build_projects');
    }

    public static function project(string $key): array
    {
        return self::projects()[$key] ?? throw new GameException('Dieses Bauprojekt gibt es nicht.');
    }

    public static function plants(): array
    {
        return self::load('garden');
    }

    public static function plant(string $key): array
    {
        return self::plants()[$key] ?? throw new GameException('Diese Pflanze gibt es nicht.');
    }

    private static function load(string $file): array
    {
        return self::$cache[$file] ??= collect(json_decode(file_get_contents(database_path("content/{$file}.json")), true, flags: JSON_THROW_ON_ERROR))
            ->keyBy('key')->all();
    }
}
