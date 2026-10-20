<?php

namespace App\Http\Requests;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Erlaubte Werte des Avatar-Baukastens (müssen zu resources/js/lib/avatar.ts passen). */
class AvatarRules
{
    public const HAIR = ['short', 'spiky', 'curly', 'long', 'ponytail', 'bun', 'bald'];

    public const EYES = ['round', 'happy', 'wink', 'sleepy'];

    public static function validate(Request $request): array
    {
        $color = ['required', 'regex:/^#[0-9a-fA-F]{6}$/'];

        return $request->validate([
            'skin' => $color,
            'hair' => ['required', Rule::in(self::HAIR)],
            'hair_color' => $color,
            'eyes' => ['required', Rule::in(self::EYES)],
            'outfit' => $color,
            'background' => $color,
        ]);
    }
}
