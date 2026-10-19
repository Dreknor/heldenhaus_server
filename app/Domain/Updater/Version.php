<?php

namespace App\Domain\Updater;

final class Version
{
    public static function normalize(string $version): string
    {
        return ltrim(trim($version), 'vV');
    }

    public static function isNewer(string $candidate, string $current): bool
    {
        return version_compare(self::normalize($candidate), self::normalize($current), '>');
    }

    /** Nur die letzte Stelle ändert sich (1.4.2 → 1.4.5) – Voraussetzung für Auto-Updates. */
    public static function isPatchUpgrade(string $candidate, string $current): bool
    {
        [$a1, $a2] = array_pad(explode('.', self::normalize($candidate)), 3, '0');
        [$b1, $b2] = array_pad(explode('.', self::normalize($current)), 3, '0');

        return $a1 === $b1 && $a2 === $b2 && self::isNewer($candidate, $current);
    }
}
