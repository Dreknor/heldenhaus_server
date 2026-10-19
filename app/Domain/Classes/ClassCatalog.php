<?php

namespace App\Domain\Classes;

use App\Enums\AgeGroup;
use App\Models\User;

/** Spielklassen aus database/content/classes.json (Konzept §2.4). */
class ClassCatalog
{
    private static ?array $classes = null;

    /** @return array<string, array> nach Schlüssel */
    public static function all(): array
    {
        return self::$classes ??= collect(json_decode(file_get_contents(database_path('content/classes.json')), true, flags: JSON_THROW_ON_ERROR))
            ->keyBy('key')
            ->all();
    }

    public static function get(?string $key): ?array
    {
        return $key ? (self::all()[$key] ?? null) : null;
    }

    /** Freischaltung: Level 5 für 9–12-Jährige, sonst Level 8. */
    public static function unlockLevel(User $user): int
    {
        return $user->ageGroup() === AgeGroup::Child ? 5 : 8;
    }

    /** Fähigkeit nach Schlüssel finden (passiv oder aktiv) samt Klasse. */
    public static function skill(string $skillKey): ?array
    {
        foreach (self::all() as $class) {
            foreach (['passive', 'active'] as $kind) {
                if (($class[$kind]['key'] ?? null) === $skillKey) {
                    return [...$class[$kind], 'kind' => $kind, 'class' => $class['key']];
                }
            }
        }

        return null;
    }
}
