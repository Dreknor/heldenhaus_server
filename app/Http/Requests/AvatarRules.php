<?php

namespace App\Http\Requests;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Erlaubte Werte des Avatar-Baukastens (müssen zu resources/js/lib/avatar.ts passen). */
class AvatarRules
{
    public const HAIR = [
        'plain', 'spiked', 'curly_short', 'cowlick', 'buzzcut', 'afro', 'bob', 'long_straight', 'wavy', 'high_ponytail', 'braid', 'bangs_bun', 'bald',
        // aus früheren Avatar-Versionen, bleiben für ältere Clients gültig
        'short', 'spiky', 'undercut', 'bun', 'long', 'curly', 'ponytail',
    ];

    public const BODIES = ['male', 'female'];

    public const HAIR_BACK = ['none', 'neck', 'shoulder', 'long', 'wavy'];

    public const EYES = ['round', 'happy', 'wink', 'sleepy', 'wide'];

    public const CLOTHES = ['tShirt', 'shirt', 'openJacket', 'turtleNeck', 'dress'];

    public const BEARDS = ['none', 'moustacheTwirl', 'chin', 'chinMoustache', 'fullBeard', 'longBeard'];

    public static function validate(Request $request): array
    {
        $color = ['required', 'regex:/^#[0-9a-fA-F]{6}$/'];

        return array_filter($request->validate([
            'skin' => $color,
            'hair' => ['required', Rule::in(self::HAIR)],
            'hair_color' => $color,
            'eyes' => ['required', Rule::in(self::EYES)],
            'outfit' => $color,
            'background' => $color,
            'body' => ['nullable', Rule::in(self::BODIES)],
            'hair_back' => ['nullable', Rule::in(self::HAIR_BACK)],
            'clothes' => ['nullable', Rule::in(self::CLOTHES)],
            'beard' => ['nullable', Rule::in(self::BEARDS)],
        ]), fn ($v) => $v !== null);
    }
}
