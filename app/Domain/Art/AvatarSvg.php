<?php

namespace App\Domain\Art;

use DiceBear\Avatar;
use DiceBear\Style;

/**
 * Avatar als SVG für Clients ohne eigenen Renderer (Home Assistant).
 * Grafik: DiceBear „Toon Head“ von Johan Melin (CC BY 4.0, siehe CREDITS.md),
 * gerendert mit dicebear/core – dieselbe Abbildung wie resources/js/lib/avatar.ts.
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

    private const SKINS = ['#f1c3a5', '#e8b48f', '#c68e7a', '#b98e6a', '#a36b4f', '#5c3829'];

    private const HAIR_COLORS = ['#2c1b18', '#724133', '#a55728', '#b58143', '#d6b370'];

    private const OUTFITS = ['#b11f1f', '#0b3286', '#147f3c', '#eab308', '#731ac3', '#f97316', '#545454', '#ec4899'];

    private const BACKGROUNDS = ['#fde68a', '#bfdbfe', '#bbf7d0', '#fbcfe8', '#ddd6fe', '#fed7aa', '#e2e8f0', '#99f6e4'];

    private const HAIR_STYLES = ['short', 'spiky', 'undercut', 'bun'];

    private const HAIR_BACK = ['none', 'neck', 'shoulder', 'long', 'wavy'];

    /** Frisuren der ersten Avatar-Version: [vorne, hinten] */
    private const LEGACY_HAIR = ['long' => ['short', 'long'], 'curly' => ['short', 'wavy'], 'ponytail' => ['bun', 'neck']];

    private const FRONT = ['short' => 'sideComed', 'spiky' => 'spiky', 'undercut' => 'undercut', 'bun' => 'bun'];

    private const BACK = ['neck' => 'neckHigh', 'shoulder' => 'shoulderHigh', 'long' => 'longStraight', 'wavy' => 'longWavy'];

    private const EYES = ['round' => 'happy', 'happy' => 'bow', 'wink' => 'wink', 'sleepy' => 'humble', 'wide' => 'wide'];

    private const CLOTHES = ['tShirt', 'shirt', 'openJacket', 'turtleNeck', 'dress'];

    private const BEARDS = ['moustacheTwirl', 'chin', 'chinMoustache', 'fullBeard', 'longBeard'];

    private static ?Style $style = null;

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
            'hair_back' => $pick(self::HAIR_BACK, 6),
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

    /** DiceBear-Optionen aus den gespeicherten Einstellungen (unbekannte Werte fallen auf Standards zurück). */
    public static function options(?array $avatar, int $seed, string $mood = 'normal'): array
    {
        $a = array_merge(self::fallback($seed), array_filter($avatar ?? [], 'is_string'));
        [$front, $back] = self::LEGACY_HAIR[$a['hair']] ?? [$a['hair'], $a['hair_back']];
        $hair = self::FRONT[$front] ?? null;
        $rear = self::BACK[$back] ?? null;
        $beard = in_array($a['beard'], self::BEARDS, true) ? $a['beard'] : null;
        [$mouth, $brows] = match ($mood) {
            'happy' => ['laugh', 'happy'],
            'hurt' => ['sad', 'sad'],
            default => ['smile', 'happy'],
        };

        return array_filter([
            'seed' => 'heldenhaus',
            'skinColor' => [self::color($a['skin']) ?? self::SKINS[0]],
            'hairColor' => [self::color($a['hair_color']) ?? self::HAIR_COLORS[0]],
            'clothesColor' => [self::color($a['outfit']) ?? self::OUTFITS[0]],
            'eyesVariant' => [self::EYES[$a['eyes']] ?? 'happy'],
            'mouthVariant' => [$mouth],
            'eyebrowsVariant' => [$brows],
            'clothesVariant' => [in_array($a['clothes'], self::CLOTHES, true) ? $a['clothes'] : 'tShirt'],
            'hairProbability' => $hair ? 100 : 0,
            'hairVariant' => $hair ? [$hair] : null,
            'rearHairProbability' => $rear ? 100 : 0,
            'rearHairVariant' => $rear ? [$rear] : null,
            'beardProbability' => $beard ? 100 : 0,
            'beardVariant' => $beard ? [$beard] : null,
        ], fn ($v) => $v !== null);
    }

    /**
     * @param  array|null  $avatar  users.avatar
     * @param  array<string, array{key: string, rarity: string, icon?: string|null}|null>  $equipment  Slot → Gegenstand
     */
    public static function render(?array $avatar, array $equipment, int $seed, bool $portrait = false, bool $backdrop = true, ?string $classIcon = null): string
    {
        self::$style ??= new Style(json_decode(file_get_contents(resource_path('avatars/toon-head.json')), true));

        $background = self::color($avatar['background'] ?? null) ?? self::fallback($seed)['background'];
        $hero = (string) new Avatar(self::$style, self::options($avatar, $seed));
        // Eigene Abmessungen setzt der Rahmen unten
        $hero = preg_replace('/^<svg\b([^>]*?)\s(?:width|height)="\d+"\s(?:width|height)="\d+"/', '<svg$1', $hero);
        $inner = $portrait
            ? preg_replace('/^<svg\b/', '<svg x="-18" y="-10" width="136" height="136"', $hero)
            : preg_replace('/^<svg\b/', '<svg x="0" y="0" width="100" height="100"', $hero);

        $b = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">';
        $b .= '<defs><clipPath id="hh-disc"><circle cx="50" cy="50" r="50"/></clipPath></defs>';
        $b .= '<g clip-path="url(#hh-disc)">';
        if ($backdrop) {
            $b .= "<rect width=\"100\" height=\"100\" fill=\"{$background}\"/>";
        }
        $b .= $inner.'</g>';

        if (! $portrait) {
            $gear = array_values(array_filter($equipment));
            $x = 50 - (count($gear) * 13 - 2) / 2;
            foreach ($gear as $i => $item) {
                $ring = self::RARITY_COLORS[$item['rarity'] ?? 'common'] ?? self::RARITY_COLORS['common'];
                $cx = $x + $i * 13 + 5.5;
                $b .= "<circle cx=\"{$cx}\" cy=\"92\" r=\"6\" fill=\"#fff\" stroke=\"{$ring}\" stroke-width=\"1.6\"/>";
                if (! empty($item['icon'])) {
                    $b .= "<text x=\"{$cx}\" y=\"94.6\" text-anchor=\"middle\" font-size=\"7\">".e(mb_substr($item['icon'], 0, 4)).'</text>';
                }
            }
            if ($classIcon) {
                $b .= '<circle cx="86" cy="14" r="10" fill="#fff" stroke="'.self::INK.'" stroke-width="1.5"/>'
                    .'<text x="86" y="18" text-anchor="middle" font-size="11">'.e(mb_substr($classIcon, 0, 4)).'</text>';
            }
        }

        return $b.'</svg>';
    }

    public static function color(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? strtolower($value) : null;
    }
}
