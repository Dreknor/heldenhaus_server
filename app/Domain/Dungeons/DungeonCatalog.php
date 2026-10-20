<?php

namespace App\Domain\Dungeons;

/** Monster, Themen, Bausteine und Händlerware aus database/content/dungeons.json (Konzept §2.7). */
class DungeonCatalog
{
    private static ?array $data = null;

    public static function all(): array
    {
        return self::$data ??= json_decode(file_get_contents(database_path('content/dungeons.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    public static function monster(string $key): array
    {
        return collect(self::all()['monsters'])->firstWhere('key', $key) ?? ['key' => $key, 'name' => $key, 'icon' => '👾', 'hp' => 20, 'attack' => 5, 'defense' => 1];
    }

    public static function themes(): array
    {
        return collect(self::all()['themes'])->keyBy('key')->all();
    }

    public static function theme(string $key): array
    {
        return self::themes()[$key] ?? array_values(self::themes())[0];
    }

    /** Thema passend zum laufenden Familien-Boss, sonst null. */
    public static function themeForQuest(?string $questKey): ?string
    {
        return $questKey ? collect(self::all()['themes'])->first(fn ($t) => in_array($questKey, $t['quests'] ?? [], true))['key'] ?? null : null;
    }

    public static function blocks(string $type): array
    {
        return self::all()['blocks'][$type] ?? [$type];
    }

    /** Grafik-Zuordnung für den Client (Tiled-Bausteine aus dem Kenney-Paket), Pfade als URLs. */
    public static function art(): array
    {
        $art = self::all()['art'];
        foreach (['tileset', 'block', 'sprites'] as $key) {
            $art[$key] = str_replace(['%7B', '%7D'], ['{', '}'], asset($art[$key]));
        }

        return $art;
    }
}
