<?php

namespace App\Domain\Events;

/** Events (§12.4) aus database/content/events.json und Zufallsereignisse (§12.5) aus fun_events.json. */
class EventCatalog
{
    private static ?array $events = null;

    private static ?array $fun = null;

    /** @return array<string, array> */
    public static function all(): array
    {
        return self::$events ??= collect(json_decode(file_get_contents(database_path('content/events.json')), true, flags: JSON_THROW_ON_ERROR))
            ->keyBy('key')->all();
    }

    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /** @return array<string, array> */
    public static function calendar(): array
    {
        return array_filter(self::all(), fn ($e) => $e['category'] === 'calendar');
    }

    /** @return array<string, array> */
    public static function fun(): array
    {
        return self::$fun ??= collect(json_decode(file_get_contents(database_path('content/fun_events.json')), true, flags: JSON_THROW_ON_ERROR))
            ->keyBy('key')->all();
    }

    public static function flush(): void
    {
        self::$events = self::$fun = null;
    }
}
