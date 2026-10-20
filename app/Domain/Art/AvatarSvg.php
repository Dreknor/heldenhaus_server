<?php

namespace App\Domain\Art;

/**
 * Avatar als SVG für Clients ohne eigenen Renderer (Home Assistant).
 * LPC-Pixelfigur aus übereinanderliegenden PNG-Ebenen (public/avatar/lpc, Credits in CREDITS.md),
 * nach denselben Regeln wie resources/js/lib/avatar.ts (beide lesen resources/avatars/lpc*.json).
 */
class AvatarSvg
{
    public const INK = '#1f2937';

    public const RARITY_COLORS = [
        'common' => '#94a3b8',
        'uncommon' => '#22c55e',
        'rare' => '#3b82f6',
        'epic' => '#a855f7',
        'legendary' => '#f59e0b',
    ];

    private const BACKGROUNDS = ['#fde68a', '#bfdbfe', '#bbf7d0', '#fbcfe8', '#ddd6fe', '#fed7aa', '#e2e8f0', '#99f6e4'];

    private const BODIES = ['male', 'female'];

    private const SLOTS = ['armor', 'head', 'weapon', 'shield', 'accessory'];

    private static ?array $manifest = null;

    private static ?array $rules = null;

    private static function manifest(): array
    {
        return self::$manifest ??= json_decode(file_get_contents(resource_path('avatars/lpc.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    private static function rules(): array
    {
        return self::$rules ??= json_decode(file_get_contents(resource_path('avatars/lpc-rules.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    /** Gleicher Startavatar wie im Web (aus der Nutzer-ID abgeleitet). */
    public static function fallback(int $seed): array
    {
        $pick = fn (array $list, int $salt) => $list[($seed * 7 + $salt) % count($list)];
        $palettes = self::manifest()['palettes'];

        return [
            'skin' => $pick(array_values($palettes['body']), 1),
            'hair' => $pick(array_slice(array_keys(self::rules()['hair']), 0, 12), 3),
            'hair_color' => $pick(array_slice(array_values($palettes['hair']), 0, 8), 5),
            'eyes' => 'round',
            'outfit' => $pick(array_values($palettes['cloth']), 2),
            'background' => $pick(self::BACKGROUNDS, 4),
            'body' => $pick(self::BODIES, 6),
            'clothes' => 'tShirt',
            'beard' => 'none',
        ];
    }

    /** Hellere/dunklere Variante einer Hex-Farbe (amount -1..1). */
    public static function shade(string $hex, float $amount = -0.18): string
    {
        $n = hexdec(ltrim($hex, '#'));
        $f = fn (int $c) => max(0, min(255, (int) round($c + $c * $amount)));

        return sprintf('#%02x%02x%02x', $f($n >> 16), $f(($n >> 8) & 0xFF), $f($n & 0xFF));
    }

    /**
     * Dateinamen der Bildebenen (von hinten nach vorn).
     *
     * @param  array<string, array{key: string, rarity: string}|null>  $equipment  Slot → Gegenstand
     */
    public static function layers(?array $avatar, int $seed, ?string $classKey = null, array $equipment = [], string $mood = 'normal'): array
    {
        $rules = self::rules();
        $a = array_merge(self::fallback($seed), array_filter($avatar ?? [], 'is_string'));
        $body = $a['body'] === 'female' ? 'female' : 'male';
        $colors = [
            '$skin' => self::nearest('body', $a['skin']),
            '$hair' => self::nearest('hair', $a['hair_color']),
            '$outfit' => self::nearest('cloth', $a['outfit']),
        ];
        $hair = $rules['hair'][self::hairKey($a)] ?? null;
        $face = $mood === 'hurt' ? $rules['hurt_face'] : ($rules['eyes'][$a['eyes']] ?? null);
        $beard = $rules['beards'][$a['beard']] ?? null;

        $wanted = [
            ...$rules['base'],
            ...($rules['clothes'][$a['clothes']] ?? $rules['clothes']['tShirt']),
            ...($face ? [[$face, '$skin']] : []),
            ...($hair ? [[$hair, '$hair']] : []),
            ...($beard ? [[$beard, '$hair']] : []),
        ];

        $costume = $rules['classes'][$classKey ?? ''] ?? [];
        array_push($wanted, ...($costume['back'] ?? []));
        foreach (self::SLOTS as $slot) {
            $item = $equipment[$slot] ?? null;
            array_push($wanted, ...($item ? self::itemParts($slot, $item) : ($costume[$slot] ?? [])));
        }

        $parts = self::manifest()['parts'];
        $layers = [];
        foreach ($wanted as [$part, $color]) {
            foreach ($parts[$part][$body][$colors[$color] ?? $color] ?? [] as [$z, $file]) {
                $layers[] = [$z, count($layers), $file];
            }
        }
        usort($layers, fn ($x, $y) => [$x[0], $x[1]] <=> [$y[0], $y[1]]);

        return array_column($layers, 2);
    }

    /**
     * @param  array|null  $avatar  users.avatar
     * @param  array<string, array{key: string, rarity: string}|null>  $equipment  Slot → Gegenstand
     */
    public static function render(?array $avatar, array $equipment, int $seed, bool $portrait = false, bool $backdrop = true, ?string $classKey = null): string
    {
        $background = self::color($avatar['background'] ?? null) ?? self::fallback($seed)['background'];
        // Ausschnitt aus dem 64×64-Bild: ganze Figur bzw. Kopf und Schultern
        $viewBox = $portrait ? '17 4 30 30' : '0 0 64 64';

        $b = "<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"{$viewBox}\" shape-rendering=\"crispEdges\">";
        if ($backdrop) {
            $b .= '<defs><clipPath id="hh-disc"><circle cx="32" cy="32" r="32"/></clipPath></defs>'
                ."<g clip-path=\"url(#hh-disc)\"><rect width=\"64\" height=\"64\" fill=\"{$background}\"/></g>";
        }
        foreach (self::layers($avatar, $seed, $classKey, array_filter($equipment), 'normal') as $file) {
            $data = base64_encode(file_get_contents(public_path('avatar/lpc/'.basename($file))));
            $b .= "<image width=\"64\" height=\"64\" style=\"image-rendering:pixelated\" href=\"data:image/png;base64,{$data}\"/>";
        }

        return $b.'</svg>';
    }

    public static function color(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? strtolower($value) : null;
    }

    private static function hairKey(array $a): string
    {
        $rules = self::rules();
        $legacyBack = $rules['hair_back_legacy'][$a['hair_back'] ?? ''] ?? null;
        if ($a['hair'] === 'short' && $legacyBack) {
            return $legacyBack;
        }

        return $rules['hair_legacy'][$a['hair']] ?? (array_key_exists($a['hair'], $rules['hair']) ? $a['hair'] : 'plain');
    }

    /** Nächste Palettenfarbe zu einem gespeicherten Hex-Wert. */
    private static function nearest(string $material, string $hex): string
    {
        $rgb = fn (string $h) => [hexdec(substr($h, 1, 2)), hexdec(substr($h, 3, 2)), hexdec(substr($h, 5, 2))];
        $target = self::color($hex) ? $rgb($hex) : [128, 128, 128];
        $best = null;
        $bestDistance = INF;
        foreach (self::manifest()['palettes'][$material] as $name => $value) {
            $c = $rgb($value);
            $d = ($c[0] - $target[0]) ** 2 + ($c[1] - $target[1]) ** 2 + ($c[2] - $target[2]) ** 2;
            if ($d < $bestDistance) {
                [$best, $bestDistance] = [$name, $d];
            }
        }

        return $best;
    }

    /** Teile für einen Gegenstand: bekannter Schlüssel, Stichwort im Schlüssel oder Slot + Seltenheit. */
    private static function itemParts(string $slot, array $item): array
    {
        $rules = self::rules();
        if (isset($rules['items'][$item['key']])) {
            return $rules['items'][$item['key']];
        }
        $r = max(0, (int) array_search($item['rarity'] ?? 'common', $rules['rarities'], true));
        $key = mb_strtolower($item['key']);
        foreach ($rules['keywords'] as $k) {
            if ($k['slot'] === $slot && collect($k['words'])->contains(fn ($w) => str_contains($key, $w))) {
                return [$k['parts'][min($r, count($k['parts']) - 1)]];
            }
        }

        return $rules['slots'][$slot][$r];
    }
}
