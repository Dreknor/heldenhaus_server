<?php

namespace App\Domain\Art;

/**
 * Avatar-Baukasten als SVG für Clients ohne eigenen Renderer (Home Assistant).
 * Port von resources/js/components/game/HeroAvatar.vue bzw. habitica_app/lib/art/avatar_svg.dart.
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

    private const SKINS = ['#fde0c5', '#f6c89f', '#e0a877', '#c48654', '#8d5a33', '#5c3a21'];

    private const HAIR_COLORS = ['#2b1d14', '#5a3825', '#a0522d', '#d9a441', '#f0d48a', '#c0392b', '#9aa3ad', '#8e44ad', '#2e86c1', '#16a085'];

    private const OUTFITS = ['#e74c3c', '#3498db', '#27ae60', '#f39c12', '#8e44ad', '#16a085', '#34495e', '#e84393'];

    private const BACKGROUNDS = ['#fde68a', '#bfdbfe', '#bbf7d0', '#fbcfe8', '#ddd6fe', '#fed7aa', '#e2e8f0', '#99f6e4'];

    private const HAIR_STYLES = ['short', 'spiky', 'curly', 'long', 'ponytail', 'bun'];

    /** Gleicher Startavatar wie im Web (aus der Nutzer-ID abgeleitet). */
    public static function fallback(int $seed): array
    {
        $pick = fn (array $list, int $salt) => $list[($seed * 7 + $salt) % count($list)];

        return [
            'skin' => $pick(self::SKINS, 1),
            'hair' => $pick(self::HAIR_STYLES, 3),
            'hair_color' => $pick(self::HAIR_COLORS, 5),
            'eyes' => 'round',
            'outfit' => $pick(self::OUTFITS, 2),
            'background' => $pick(self::BACKGROUNDS, 4),
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
     * @param  array|null  $avatar  users.avatar
     * @param  array<string, array{key: string, rarity: string}|null>  $equipment  Slot → Gegenstand
     */
    public static function render(?array $avatar, array $equipment, int $seed, bool $portrait = false, bool $backdrop = true): string
    {
        $a = array_merge(self::fallback($seed), array_filter($avatar ?? [], 'is_string'));
        $a = array_map(fn ($v) => self::color($v) ?? preg_replace('/[^a-z_]/', '', $v), $a);
        $eq = array_filter($equipment);

        $ink = self::INK;
        $rarity = fn (string $slot) => self::RARITY_COLORS[$eq[$slot]['rarity'] ?? 'common'] ?? self::RARITY_COLORS['common'];
        $hoodie = ($eq['armor']['key'] ?? null) === 'armor_hoodie';
        $sleeve = $hoodie ? '#7c3aed' : $a['outfit'];
        $legs = self::shade($a['outfit'], -0.35);
        $skinShade = self::shade($a['skin'], -0.12);
        $hairShade = self::shade($a['hair_color'], -0.25);
        $hc = $a['hair_color'];
        $viewBox = $portrait ? '14 8 72 72' : '0 0 100 120';

        $b = "<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"{$viewBox}\" stroke-linejoin=\"round\" stroke-linecap=\"round\">";

        if ($backdrop) {
            $b .= "<circle cx=\"50\" cy=\"58\" r=\"47\" fill=\"{$a['background']}\"/>";
        }
        $b .= '<ellipse cx="50" cy="113" rx="24" ry="4" fill="#000" opacity=".12"/>';

        // Haare hinten
        $b .= match ($a['hair']) {
            'long' => "<path d=\"M27 40 Q24 74 32 82 L68 82 Q76 74 73 40 Z\" fill=\"{$hc}\" stroke=\"{$ink}\" stroke-width=\"2.5\"/>",
            'ponytail' => "<path d=\"M70 34 Q88 40 82 66 Q80 72 74 70 Q80 54 68 44 Z\" fill=\"{$hc}\" stroke=\"{$ink}\" stroke-width=\"2.5\"/>",
            'bun' => "<circle cx=\"50\" cy=\"17\" r=\"9\" fill=\"{$hc}\" stroke=\"{$ink}\" stroke-width=\"2.5\"/>",
            default => '',
        };

        // Beine
        $b .= "<rect x=\"36\" y=\"94\" width=\"11\" height=\"17\" rx=\"4\" fill=\"{$legs}\" stroke=\"{$ink}\" stroke-width=\"2.5\"/>"
            ."<rect x=\"53\" y=\"94\" width=\"11\" height=\"17\" rx=\"4\" fill=\"{$legs}\" stroke=\"{$ink}\" stroke-width=\"2.5\"/>"
            ."<path d=\"M33 111 h15 v3 h-15 Z M52 111 h15 v3 h-15 Z\" fill=\"{$ink}\"/>";

        // Schild
        if (isset($eq['shield'])) {
            $b .= $eq['shield']['key'] === 'shield_lid'
                ? "<circle cx=\"22\" cy=\"80\" r=\"13\" fill=\"#cbd5e1\" stroke=\"{$ink}\" stroke-width=\"2.5\"/>"
                    .'<circle cx="22" cy="80" r="8" fill="none" stroke="#94a3b8" stroke-width="2"/>'
                    ."<circle cx=\"22\" cy=\"80\" r=\"3\" fill=\"#64748b\" stroke=\"{$ink}\" stroke-width=\"1.5\"/>"
                : "<path d=\"M10 70 h24 v10 Q34 94 22 98 Q10 94 10 80 Z\" fill=\"{$rarity('shield')}\" stroke=\"{$ink}\" stroke-width=\"2.5\"/>"
                    .'<path d="M22 72 v24 M12 80 h20" stroke="#fff" stroke-width="2" opacity=".7"/>';
        }

        // Körper
        $b .= "<path d=\"M31 72 Q31 64 40 64 L60 64 Q69 64 69 72 L69 96 L31 96 Z\" fill=\"{$a['outfit']}\" stroke=\"{$ink}\" stroke-width=\"2.5\"/>"
            .'<path d="M44 64 L50 72 L56 64" fill="none" stroke="'.self::shade($a['outfit'], -0.3).'" stroke-width="2"/>';

        // Rüstung
        if (isset($eq['armor'])) {
            if ($hoodie) {
                $b .= "<path d=\"M30 72 Q30 63 40 63 L60 63 Q70 63 70 72 L70 97 L30 97 Z\" fill=\"#7c3aed\" stroke=\"{$ink}\" stroke-width=\"2.5\"/>"
                    ."<path d=\"M41 84 h18 v8 h-18 Z\" fill=\"#6d28d9\" stroke=\"{$ink}\" stroke-width=\"1.8\"/>"
                    .'<path d="M46 64 v10 M54 64 v10" stroke="#f5f3ff" stroke-width="1.8"/>';
            } else {
                $b .= "<path d=\"M32 70 Q32 64 40 64 L60 64 Q68 64 68 70 L66 92 L34 92 Z\" fill=\"{$rarity('armor')}\" stroke=\"{$ink}\" stroke-width=\"2.5\"/>";
                foreach ([40, 50, 60] as $x) {
                    $b .= "<circle cx=\"{$x}\" cy=\"72\" r=\"1.6\" fill=\"#fff\" opacity=\".8\"/>";
                }
            }
        }

        // Arme
        foreach (['M31 70 Q22 76 24 88', 'M69 70 Q78 76 76 88'] as $d) {
            $b .= "<path d=\"{$d}\" fill=\"none\" stroke=\"{$ink}\" stroke-width=\"9\"/><path d=\"{$d}\" fill=\"none\" stroke=\"{$sleeve}\" stroke-width=\"5.5\"/>";
        }
        $b .= "<circle cx=\"24\" cy=\"89\" r=\"4.5\" fill=\"{$a['skin']}\" stroke=\"{$ink}\" stroke-width=\"2.2\"/>";

        // Waffe
        if (isset($eq['weapon'])) {
            $wood = $eq['weapon']['key'] === 'weapon_wooden_sword';
            $b .= $wood
                ? "<path d=\"M78 86 L90 52 L94 54 L82 88 Z\" fill=\"#c08a52\" stroke=\"{$ink}\" stroke-width=\"2.2\"/>"
                : "<path d=\"M78 86 L92 48 L96 50 L82 88 Z\" fill=\"{$rarity('weapon')}\" stroke=\"{$ink}\" stroke-width=\"2.2\"/>";
            $b .= "<path d=\"M72 84 L86 90\" stroke=\"{$ink}\" stroke-width=\"5\"/><path d=\"M72 84 L86 90\" stroke=\"".($wood ? '#8b5a2b' : '#fbbf24').'" stroke-width="2.5"/>';
        }
        $b .= "<circle cx=\"76\" cy=\"89\" r=\"4.5\" fill=\"{$a['skin']}\" stroke=\"{$ink}\" stroke-width=\"2.2\"/>";

        // Kopf
        $b .= "<circle cx=\"29\" cy=\"45\" r=\"4\" fill=\"{$skinShade}\" stroke=\"{$ink}\" stroke-width=\"2.2\"/>"
            ."<circle cx=\"71\" cy=\"45\" r=\"4\" fill=\"{$skinShade}\" stroke=\"{$ink}\" stroke-width=\"2.2\"/>"
            ."<circle cx=\"50\" cy=\"42\" r=\"21\" fill=\"{$a['skin']}\" stroke=\"{$ink}\" stroke-width=\"2.5\"/>";

        // Haare vorne
        switch ($a['hair']) {
            case 'short':
                $b .= "<path d=\"M29 40 Q30 20 50 20 Q70 20 71 40 Q62 30 50 33 Q40 30 29 40 Z\" fill=\"{$hc}\" stroke=\"{$ink}\" stroke-width=\"2.5\"/>";
                break;
            case 'spiky':
                $b .= '<path d="M29 41 L28 28 L36 31 L38 19 L46 27 L52 16 L57 27 L65 20 L66 31 L73 29 L71 41 Q60 31 50 32 Q40 31 29 41 Z" '
                    ."fill=\"{$hc}\" stroke=\"{$ink}\" stroke-width=\"2.5\"/>";
                break;
            case 'curly':
                foreach ([[32, 34], [38, 25], [47, 21], [56, 22], [64, 27], [69, 35]] as [$cx, $cy]) {
                    $b .= "<circle cx=\"{$cx}\" cy=\"{$cy}\" r=\"8\" fill=\"{$hc}\" stroke=\"{$ink}\" stroke-width=\"2.3\"/>";
                }
                break;
            case 'long':
            case 'ponytail':
            case 'bun':
                $b .= "<path d=\"M29 42 Q29 21 50 21 Q71 21 71 42 Q66 31 56 30 Q52 36 44 34 Q36 33 29 42 Z\" fill=\"{$hc}\" stroke=\"{$ink}\" stroke-width=\"2.5\"/>";
                break;
        }
        if ($a['hair'] !== 'bald') {
            $b .= "<path d=\"M40 25 Q47 22 54 24\" fill=\"none\" stroke=\"{$hairShade}\" stroke-width=\"2\" opacity=\".7\"/>";
        }

        // Augen
        $b .= match ($a['eyes']) {
            'happy' => "<path d=\"M39 44 Q42.5 40 46 44 M54 44 Q57.5 40 61 44\" fill=\"none\" stroke=\"{$ink}\" stroke-width=\"2.4\"/>",
            'sleepy' => "<path d=\"M39 44 h7 M54 44 h7\" stroke=\"{$ink}\" stroke-width=\"2.4\"/>",
            'wink' => "<circle cx=\"42.5\" cy=\"44\" r=\"3\" fill=\"{$ink}\"/><path d=\"M54 44 Q57.5 40 61 44\" fill=\"none\" stroke=\"{$ink}\" stroke-width=\"2.4\"/>",
            default => "<circle cx=\"42.5\" cy=\"44\" r=\"3\" fill=\"{$ink}\"/><circle cx=\"57.5\" cy=\"44\" r=\"3\" fill=\"{$ink}\"/>"
                .'<circle cx="43.5" cy="43" r="1" fill="#fff"/><circle cx="58.5" cy="43" r="1" fill="#fff"/>',
        };
        $b .= '<circle cx="37" cy="51" r="3" fill="#f87171" opacity=".35"/><circle cx="63" cy="51" r="3" fill="#f87171" opacity=".35"/>';
        $b .= "<path d=\"M44 52 Q50 58 56 52\" fill=\"none\" stroke=\"{$ink}\" stroke-width=\"2.4\"/>";

        // Accessoire
        if (isset($eq['accessory'])) {
            $b .= $eq['accessory']['key'] === 'accessory_glasses'
                ? "<circle cx=\"42.5\" cy=\"44\" r=\"6\" fill=\"#bfdbfe\" fill-opacity=\".35\" stroke=\"{$ink}\" stroke-width=\"2.2\"/>"
                    ."<circle cx=\"57.5\" cy=\"44\" r=\"6\" fill=\"#bfdbfe\" fill-opacity=\".35\" stroke=\"{$ink}\" stroke-width=\"2.2\"/>"
                    ."<path d=\"M48.5 44 h3\" stroke=\"{$ink}\" stroke-width=\"2.2\"/>"
                : '<path d="M43 64 Q50 72 57 64" fill="none" stroke="#fbbf24" stroke-width="1.8"/>'
                    ."<circle cx=\"50\" cy=\"71\" r=\"3.5\" fill=\"{$rarity('accessory')}\" stroke=\"{$ink}\" stroke-width=\"1.8\"/>";
        }

        // Kopfbedeckung
        if (isset($eq['head'])) {
            $b .= $eq['head']['key'] === 'head_cap'
                ? "<path d=\"M29 34 Q30 17 50 17 Q70 17 71 34 Z\" fill=\"#ef4444\" stroke=\"{$ink}\" stroke-width=\"2.5\"/>"
                    ."<path d=\"M54 34 Q72 33 80 37 Q70 40 54 38 Z\" fill=\"#dc2626\" stroke=\"{$ink}\" stroke-width=\"2.3\"/>"
                    ."<circle cx=\"50\" cy=\"17\" r=\"2.5\" fill=\"#fff\" stroke=\"{$ink}\" stroke-width=\"1.5\"/>"
                : "<path d=\"M27 38 Q27 15 50 15 Q73 15 73 38 L66 36 Q50 30 34 36 Z\" fill=\"{$rarity('head')}\" stroke=\"{$ink}\" stroke-width=\"2.5\"/>"
                    ."<path d=\"M50 15 v-6\" stroke=\"{$ink}\" stroke-width=\"2.5\"/><circle cx=\"50\" cy=\"8\" r=\"3\" fill=\"#fbbf24\" stroke=\"{$ink}\" stroke-width=\"1.8\"/>";
        }

        return $b.'</svg>';
    }

    /** Nur echte Hex-Farben durchlassen – die Werte landen ungefiltert in SVG-Attributen. */
    public static function color(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? strtolower($value) : null;
    }
}
