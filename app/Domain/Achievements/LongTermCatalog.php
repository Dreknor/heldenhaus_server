<?php

namespace App\Domain\Achievements;

/**
 * Inhalte der Langzeitziele (§14.7) aus database/content/long_term.json: Alben, Meisterschaften,
 * Titel und Heldenjahr. Zahlen und Belohnungen sind Inhalt, programmiert sind nur die Regeln.
 */
class LongTermCatalog
{
    private static ?array $data = null;

    public static function all(): array
    {
        return self::$data ??= json_decode(file_get_contents(database_path('content/long_term.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    public static function flush(): void
    {
        self::$data = null;
    }

    /** @return array<string, array> Alben nach Schlüssel */
    public static function albums(): array
    {
        return collect(self::all()['albums']['list'])->keyBy('key')->all();
    }

    public static function masteries(): array
    {
        return self::all()['masteries'];
    }

    /** @return list<array{level: int, key: string, name: string, icon: string}> */
    public static function titles(): array
    {
        return self::all()['titles'];
    }

    public static function heroYear(): array
    {
        return self::all()['hero_year'];
    }
}
