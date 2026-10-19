<?php

namespace App\Enums;

enum FamilyRole: string
{
    case Parent = 'parent';
    case Child = 'child';
    case Guest = 'guest';

    public function label(): string
    {
        return match ($this) {
            self::Parent => 'Elternteil',
            self::Child => 'Kind',
            self::Guest => 'Gast',
        };
    }
}
