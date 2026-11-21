<?php

namespace App\Domain\Events;

use App\Models\EventOverride;
use Illuminate\Database\QueryException;

/**
 * Events (§12.4) aus database/content/events.json und Zufallsereignisse (§12.5) aus fun_events.json.
 * Änderungen aus dem Admin-Editor (Tabelle event_overrides) liegen feldweise darüber, die Dateien bleiben unverändert.
 */
class EventCatalog
{
    private static ?array $events = null;

    private static ?array $fun = null;

    private static ?array $baseEvents = null;

    private static ?array $baseFun = null;

    /** @var array<string, array<string, array>>|null kind => key => geänderte Felder */
    private static ?array $overrides = null;

    /** @return array<string, array> */
    public static function all(): array
    {
        return self::$events ??= self::apply(self::baseAll(), 'event');
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
        return self::$fun ??= self::apply(self::baseFun(), 'fun');
    }

    /** Events so, wie sie in events.json stehen (ohne Admin-Änderungen). @return array<string, array> */
    public static function baseAll(): array
    {
        return self::$baseEvents ??= self::load('events.json');
    }

    /** @return array<string, array> */
    public static function baseFun(): array
    {
        return self::$baseFun ??= self::load('fun_events.json');
    }

    /** Geänderte Felder je Schlüssel. @return array<string, array> */
    public static function overrides(string $kind): array
    {
        if (self::$overrides === null) {
            self::$overrides = ['event' => [], 'fun' => []];
            try {
                foreach (EventOverride::all(['kind', 'key', 'data']) as $row) {
                    self::$overrides[$row->kind][$row->key] = $row->data ?? [];
                }
            } catch (QueryException) {
                // Tabelle fehlt (z. B. vor der Migration): nur die Dateien
            }
        }

        return self::$overrides[$kind] ?? [];
    }

    public static function flush(): void
    {
        self::$events = self::$fun = self::$baseEvents = self::$baseFun = self::$overrides = null;
    }

    private static function load(string $file): array
    {
        return collect(json_decode(file_get_contents(database_path("content/{$file}")), true, flags: JSON_THROW_ON_ERROR))
            ->keyBy('key')->all();
    }

    /** Admin-Änderungen ersetzen ganze Felder der obersten Ebene; Schlüssel und Kategorie bleiben fest. */
    private static function apply(array $base, string $kind): array
    {
        foreach (self::overrides($kind) as $key => $fields) {
            if (isset($base[$key])) {
                $base[$key] = array_replace($base[$key], $fields, ['key' => $key]);
            }
        }

        return $base;
    }
}
