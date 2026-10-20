<?php

namespace App\Domain\Art;

/**
 * Monster-Baukasten als SVG für Home Assistant.
 * Port von resources/js/components/game/MonsterArt.vue bzw. habitica_app/lib/art/monster_svg.dart.
 * $art kommt aus quests.json („art“): body, color, accent, eyes, horns, mouth, extra.
 */
class MonsterSvg
{
    public static function render(?array $art, bool $defeated = false): string
    {
        $ink = AvatarSvg::INK;
        $body = $art['body'] ?? 'blob';
        $color = AvatarSvg::color($art['color'] ?? null) ?? '#8b5cf6';
        $accent = AvatarSvg::color($art['accent'] ?? null) ?? '#fde68a';
        $eyes = (int) ($art['eyes'] ?? 2);
        $horns = $art['horns'] ?? 'none';
        $mouth = $art['mouth'] ?? 'grin';
        $extra = $art['extra'] ?? 'none';
        $dark = AvatarSvg::shade($color, -0.25);
        $light = AvatarSvg::shade($color, 0.25);

        [$fx, $fy] = match ($body) {
            'troll' => [80, 58],
            'serpent', 'dragon' => [92, 52],
            'golem' => [80, 62],
            default => [80, 70],
        };
        $eyeXs = match ($eyes) {
            1 => [0],
            3 => [-14, 0, 14],
            default => [-11, 11],
        };

        $b = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 160 150" stroke-linejoin="round" stroke-linecap="round">';
        $b .= '<ellipse cx="80" cy="140" rx="46" ry="6" fill="#000" opacity=".15"/>';

        if ($extra === 'wings') {
            $b .= "<g fill=\"{$dark}\" stroke=\"{$ink}\" stroke-width=\"3\"><path d=\"M44 70 Q8 40 14 92 Q28 80 44 92 Z\"/><path d=\"M116 70 Q152 40 146 92 Q132 80 116 92 Z\"/></g>";
        }
        if ($extra === 'heads') {
            $b .= "<path d=\"M70 112 Q56 80 46 52\" fill=\"none\" stroke=\"{$ink}\" stroke-width=\"15\"/><path d=\"M70 112 Q56 80 46 52\" fill=\"none\" stroke=\"{$color}\" stroke-width=\"9.5\"/>"
                ."<ellipse cx=\"42\" cy=\"46\" rx=\"14\" ry=\"11\" fill=\"{$color}\" stroke=\"{$ink}\" stroke-width=\"3\"/>"
                ."<circle cx=\"38\" cy=\"43\" r=\"4\" fill=\"#fff\" stroke=\"{$ink}\" stroke-width=\"2\"/><circle cx=\"37\" cy=\"43\" r=\"2\" fill=\"{$ink}\"/>"
                ."<path d=\"M30 51 Q36 55 42 51\" fill=\"none\" stroke=\"{$ink}\" stroke-width=\"2.5\"/>"
                ."<path d=\"M58 122 Q40 108 24 94\" fill=\"none\" stroke=\"{$ink}\" stroke-width=\"13\"/><path d=\"M58 122 Q40 108 24 94\" fill=\"none\" stroke=\"{$color}\" stroke-width=\"8\"/>"
                ."<ellipse cx=\"20\" cy=\"90\" rx=\"11\" ry=\"9\" fill=\"{$color}\" stroke=\"{$ink}\" stroke-width=\"3\"/>"
                ."<circle cx=\"17\" cy=\"88\" r=\"3.5\" fill=\"#fff\" stroke=\"{$ink}\" stroke-width=\"2\"/><circle cx=\"16\" cy=\"88\" r=\"1.8\" fill=\"{$ink}\"/>";
        }

        $b .= "<g stroke=\"{$ink}\" stroke-width=\"3.5\">";
        switch ($body) {
            case 'round':
                $b .= "<ellipse cx=\"80\" cy=\"84\" rx=\"50\" ry=\"48\" fill=\"{$color}\"/>";
                break;
            case 'golem':
                $b .= "<path d=\"M34 132 L30 54 Q30 36 50 36 L110 36 Q130 36 130 54 L126 132 Z\" fill=\"{$color}\"/>";
                break;
            case 'ghost':
                $b .= "<path d=\"M34 130 Q30 40 80 30 Q130 40 126 130 L114 118 L102 132 L90 118 L80 132 L70 118 L58 132 L46 118 Z\" fill=\"{$color}\" opacity=\".95\"/>";
                break;
            case 'serpent':
                $b .= "<path d=\"M24 128 Q30 100 62 106 Q98 112 96 82 Q94 50 110 36 Q126 24 132 44 Q138 66 116 74 Q112 104 84 124 Q60 138 24 128 Z\" fill=\"{$color}\"/>"
                    ."<path d=\"M40 120 Q60 112 80 118\" fill=\"none\" stroke=\"{$light}\" stroke-width=\"4\"/>";
                break;
            case 'octopus':
                for ($i = 1; $i <= 6; $i++) {
                    $d = 'M'.(36 + $i * 14).' 110 Q'.(30 + $i * 14).' 134 '.(22 + $i * 16).' 136';
                    $b .= "<path d=\"{$d}\" fill=\"none\" stroke=\"{$ink}\" stroke-width=\"12\"/><path d=\"{$d}\" fill=\"none\" stroke=\"{$color}\" stroke-width=\"7\"/>";
                }
                $b .= "<ellipse cx=\"80\" cy=\"74\" rx=\"48\" ry=\"44\" fill=\"{$color}\"/>";
                break;
            case 'dragon':
                $b .= "<path d=\"M20 128 Q30 104 52 110 L58 74 Q60 50 86 44 Q102 26 120 34 Q136 40 132 58 Q128 72 110 72 L104 120 Q84 136 20 128 Z\" fill=\"{$color}\"/>"
                    ."<path d=\"M62 96 Q78 104 98 98 L96 118 Q78 124 62 116 Z\" fill=\"{$accent}\"/>";
                break;
            case 'troll':
                $b .= "<path d=\"M40 132 L36 70 Q34 30 80 28 Q126 30 124 70 L120 132 Z\" fill=\"{$color}\"/>"
                    ."<path d=\"M36 70 Q22 72 24 90 Q32 92 38 84 Z M124 70 Q138 72 136 90 Q128 92 122 84 Z\" fill=\"{$color}\"/>";
                break;
            case 'king':
                $b .= "<path d=\"M28 134 Q24 80 46 60 L114 60 Q136 80 132 134 Z\" fill=\"{$accent}\"/><ellipse cx=\"80\" cy=\"58\" rx=\"34\" ry=\"32\" fill=\"{$color}\"/>";
                break;
            default:
                $b .= "<path d=\"M30 128 Q20 60 80 36 Q140 60 130 128 Q118 120 108 130 Q96 120 82 130 Q68 120 56 130 Q42 120 30 128 Z\" fill=\"{$color}\"/>";
        }
        $b .= '</g>';

        if (in_array($body, ['blob', 'round', 'golem'], true)) {
            $b .= "<ellipse cx=\"80\" cy=\"106\" rx=\"26\" ry=\"18\" fill=\"{$accent}\" opacity=\".85\"/>";
        }
        if (! in_array($body, ['serpent', 'dragon'], true)) {
            $b .= '<ellipse cx="58" cy="54" rx="10" ry="6" fill="#fff" opacity=".35" transform="rotate(-25 58 54)"/>';
        }
        if ($extra === 'spikes') {
            $b .= "<g fill=\"{$accent}\" stroke=\"{$ink}\" stroke-width=\"2.5\"><path d=\"M50 44 L56 26 L64 40 Z\"/><path d=\"M74 36 L80 18 L88 34 Z\"/><path d=\"M98 40 L106 26 L110 44 Z\"/></g>";
        }

        if ($horns === 'two') {
            $b .= "<g fill=\"{$accent}\" stroke=\"{$ink}\" stroke-width=\"3\">"
                .'<path d="M'.($fx - 26).' '.($fy - 26).' Q'.($fx - 40).' '.($fy - 50).' '.($fx - 22).' '.($fy - 52).' Q'.($fx - 26).' '.($fy - 38).' '.($fx - 14).' '.($fy - 30).' Z"/>'
                .'<path d="M'.($fx + 26).' '.($fy - 26).' Q'.($fx + 40).' '.($fy - 50).' '.($fx + 22).' '.($fy - 52).' Q'.($fx + 26).' '.($fy - 38).' '.($fx + 14).' '.($fy - 30).' Z"/></g>';
        } elseif ($horns === 'crown') {
            $b .= '<path d="M'.($fx - 24).' '.($fy - 28).' L'.($fx - 24).' '.($fy - 50).' L'.($fx - 12).' '.($fy - 38).' L'.$fx.' '.($fy - 56).' L'.($fx + 12).' '.($fy - 38)
                .' L'.($fx + 24).' '.($fy - 50).' L'.($fx + 24).' '.($fy - 28)." Z\" fill=\"#fbbf24\" stroke=\"{$ink}\" stroke-width=\"3\"/>";
        } elseif ($horns === 'antenna') {
            $b .= "<g stroke=\"{$ink}\" stroke-width=\"3\" fill=\"none\">"
                .'<path d="M'.($fx - 10).' '.($fy - 30).' Q'.($fx - 20).' '.($fy - 50).' '.($fx - 28).' '.($fy - 52).'"/>'
                .'<path d="M'.($fx + 10).' '.($fy - 30).' Q'.($fx + 20).' '.($fy - 50).' '.($fx + 28).' '.($fy - 52).'"/>'
                .'<circle cx="'.($fx - 30).'" cy="'.($fy - 53)."\" r=\"5\" fill=\"{$accent}\"/><circle cx=\"".($fx + 30).'" cy="'.($fy - 53)."\" r=\"5\" fill=\"{$accent}\"/></g>";
        }

        foreach ($eyeXs as $dx) {
            $x = $fx + $dx;
            $b .= "<ellipse cx=\"{$x}\" cy=\"".($fy - 8)."\" rx=\"9\" ry=\"10\" fill=\"#fff\" stroke=\"{$ink}\" stroke-width=\"3\"/>";
            $b .= $defeated
                ? '<path d="M'.($x - 5).' '.($fy - 13).' l10 10 M'.($x + 5).' '.($fy - 13)." l-10 10\" stroke=\"{$ink}\" stroke-width=\"3\"/>"
                : '<circle cx="'.($x + 2).'" cy="'.($fy - 6)."\" r=\"4.5\" fill=\"{$ink}\"/>";
            $b .= '<path d="M'.($x - 10).' '.($fy - 21).' L'.($x + 8).' '.($fy - 17)."\" stroke=\"{$ink}\" stroke-width=\"3.5\"/>";
        }

        if ($body === 'troll') {
            $b .= "<ellipse cx=\"80\" cy=\"64\" rx=\"8\" ry=\"7\" fill=\"{$dark}\" stroke=\"{$ink}\" stroke-width=\"3\"/>";
        }

        $b .= "<g stroke=\"{$ink}\" stroke-width=\"3\">";
        $b .= match ($mouth) {
            'fangs' => '<path d="M'.($fx - 16).' '.($fy + 10).' Q'.$fx.' '.($fy + 22).' '.($fx + 16).' '.($fy + 10)."\" fill=\"{$dark}\"/>"
                .'<path d="M'.($fx - 9).' '.($fy + 12).' l3 7 l3 -6 M'.($fx + 3).' '.($fy + 13).' l3 6 l3 -7" fill="#fff" stroke-width="2"/>',
            'o' => "<ellipse cx=\"{$fx}\" cy=\"".($fy + 14)."\" rx=\"7\" ry=\"8\" fill=\"{$dark}\"/>",
            'frown' => '<path d="M'.($fx - 14).' '.($fy + 18).' Q'.$fx.' '.($fy + 6).' '.($fx + 14).' '.($fy + 18).'" fill="none"/>',
            default => '<path d="M'.($fx - 16).' '.($fy + 8).' Q'.$fx.' '.($fy + 24).' '.($fx + 16).' '.($fy + 8).'" fill="none"/>',
        };
        $b .= '</g>';

        if ($extra === 'dust') {
            $b .= "<g fill=\"#d6d3d1\" stroke=\"{$ink}\" stroke-width=\"2\" opacity=\".9\"><circle cx=\"22\" cy=\"118\" r=\"8\"/><circle cx=\"32\" cy=\"124\" r=\"6\"/>"
                .'<circle cx="138" cy="114" r="8"/><circle cx="128" cy="124" r="5"/></g>';
        }

        return $b.'</svg>';
    }
}
