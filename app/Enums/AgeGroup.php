<?php

namespace App\Enums;

enum AgeGroup: string
{
    case Child = 'child';   // 9–12
    case Teen = 'teen';     // 13–16
    case Adult = 'adult';

    public static function fromBirthYear(?int $birthYear, ?int $currentYear = null): self
    {
        if ($birthYear === null) {
            return self::Adult;
        }

        $age = ($currentYear ?? (int) now()->year) - $birthYear;

        return match (true) {
            $age <= 12 => self::Child,
            $age <= 17 => self::Teen,
            default => self::Adult,
        };
    }
}
