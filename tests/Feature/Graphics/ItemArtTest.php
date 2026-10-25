<?php

// Jeder Gegenstand und jede Tierart bringt ein Bild mit (tools/emoji/build_emoji.py, tools/pets/build_pets.py).
function emojiCode(string $glyph): string
{
    return collect(mb_str_split(str_replace("\u{FE0F}", '', $glyph)))->map(fn ($c) => dechex(mb_ord($c)))->implode('-');
}

it('hat für jedes Item-Icon ein 3D-Bild', function () {
    $available = json_decode(file_get_contents(public_path('images/emoji/index.json')), true);
    $items = json_decode(file_get_contents(database_path('content/items.json')), true);

    $missing = collect($items)->reject(fn ($i) => in_array(emojiCode($i['icon']), $available, true))->pluck('key');
    expect($missing->all())->toBe([]);

    foreach ($available as $code) {
        expect(public_path("images/emoji/{$code}.webp"))->toBeFile();
    }
});

it('hat für jede Tierart ein Bild, auch als Reittier', function () {
    $eggs = collect(json_decode(file_get_contents(database_path('content/items.json')), true))->where('type', 'egg');

    expect($eggs)->not->toBeEmpty();
    foreach ($eggs as $egg) {
        expect(public_path('images/pets/'.substr($egg['key'], 4).'.webp'))->toBeFile();
    }
});
