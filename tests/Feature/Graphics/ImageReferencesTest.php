<?php

// Feste Bildpfade im Frontend dürfen nicht ins Leere zeigen (z. B. nach dem Neuerzeugen der Emoji-Bilder).

it('findet für jeden festen Bildpfad im Frontend eine Datei', function () {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('js')));
    $missing = [];

    foreach ($files as $file) {
        if (! in_array($file->getExtension(), ['vue', 'ts'], true)) {
            continue;
        }
        preg_match_all('#[\'"`(](/images/[A-Za-z0-9_./-]+\.(?:png|webp|svg|jpg))[\'"`)]#', file_get_contents($file->getPathname()), $m);
        foreach ($m[1] as $path) {
            if (! is_file(public_path(ltrim($path, '/')))) {
                $missing[] = basename($file->getPathname()).': '.$path;
            }
        }
    }

    expect($missing)->toBe([]);
});

it('hat für jedes im Frontend fest verwendete Emoji ein Bild', function () {
    $codes = json_decode(file_get_contents(public_path('images/emoji/index.json')), true);
    $missing = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('js'))) as $file) {
        if ($file->getExtension() !== 'vue') {
            continue;
        }
        preg_match_all("#emojiUrl\\('([^']+)'\\)#u", file_get_contents($file->getPathname()), $m);
        foreach ($m[1] as $glyph) {
            $code = implode('-', array_map(fn ($c) => dechex(mb_ord($c)), array_filter(mb_str_split($glyph), fn ($c) => mb_ord($c) !== 0xFE0F)));
            if (! in_array($code, $codes, true)) {
                $missing[] = basename($file->getPathname()).': '.$glyph;
            }
        }
    }

    expect($missing)->toBe([]);
});
