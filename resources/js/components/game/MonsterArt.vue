<script setup lang="ts">
import { shade } from '@/lib/avatar';
import { computed } from 'vue';

/**
 * Monster-Baukasten (Konzept §2.9): Jeder Quest-Boss wird aus Körperform, Farben, Augen,
 * Hörnern, Mund und Extras zusammengesetzt – Werte stehen in database/content/quests.json („art“).
 */
export interface MonsterArtConfig {
    body: 'blob' | 'round' | 'golem' | 'ghost' | 'serpent' | 'octopus' | 'dragon' | 'troll' | 'king';
    color: string;
    accent?: string;
    eyes?: 1 | 2 | 3;
    horns?: 'none' | 'two' | 'crown' | 'antenna';
    mouth?: 'grin' | 'fangs' | 'o' | 'frown';
    extra?: 'none' | 'wings' | 'spikes' | 'heads' | 'dust';
}

const props = withDefaults(defineProps<{ art?: MonsterArtConfig | null; size?: number; hit?: boolean; defeated?: boolean }>(), {
    art: null,
    size: 140,
    hit: false,
    defeated: false,
});

const ink = '#1f2937';
const m = computed<Required<MonsterArtConfig>>(() => ({
    body: 'blob',
    color: '#8b5cf6',
    accent: '#fde68a',
    eyes: 2,
    horns: 'none',
    mouth: 'grin',
    extra: 'none',
    ...(props.art ?? {}),
}));
const dark = computed(() => shade(m.value.color, -0.25));
const light = computed(() => shade(m.value.color, 0.25));

// Position von Gesicht und Kopf je Körperform
const face = computed(() => {
    switch (m.value.body) {
        case 'troll':
            return { x: 80, y: 58 };
        case 'serpent':
        case 'dragon':
            return { x: 92, y: 52 };
        case 'golem':
            return { x: 80, y: 62 };
        default:
            return { x: 80, y: 70 };
    }
});
const eyeXs = computed(() => (m.value.eyes === 1 ? [0] : m.value.eyes === 3 ? [-14, 0, 14] : [-11, 11]));
</script>

<template>
    <svg
        viewBox="0 0 160 150"
        :width="size"
        :height="size * 0.94"
        xmlns="http://www.w3.org/2000/svg"
        role="img"
        aria-label="Boss"
        stroke-linejoin="round"
        stroke-linecap="round"
        class="monster"
        :class="{ 'is-hit': hit, 'is-defeated': defeated }"
    >
        <ellipse cx="80" cy="140" rx="46" ry="6" fill="#000" opacity=".15" />

        <!-- Extras hinter dem Körper -->
        <g v-if="m.extra === 'wings'" :fill="dark" :stroke="ink" stroke-width="3">
            <path d="M44 70 Q8 40 14 92 Q28 80 44 92 Z" />
            <path d="M116 70 Q152 40 146 92 Q132 80 116 92 Z" />
        </g>
        <!-- Zusätzliche Köpfe (Hydra): Hals als dicker Strich, kleiner Kopf mit Auge -->
        <g v-if="m.extra === 'heads'">
            <path d="M70 112 Q56 80 46 52" fill="none" :stroke="ink" stroke-width="15" />
            <path d="M70 112 Q56 80 46 52" fill="none" :stroke="m.color" stroke-width="9.5" />
            <ellipse cx="42" cy="46" rx="14" ry="11" :fill="m.color" :stroke="ink" stroke-width="3" />
            <circle cx="38" cy="43" r="4" fill="#fff" :stroke="ink" stroke-width="2" />
            <circle cx="37" cy="43" r="2" :fill="ink" />
            <path d="M30 51 Q36 55 42 51" fill="none" :stroke="ink" stroke-width="2.5" />
            <path d="M58 122 Q40 108 24 94" fill="none" :stroke="ink" stroke-width="13" />
            <path d="M58 122 Q40 108 24 94" fill="none" :stroke="m.color" stroke-width="8" />
            <ellipse cx="20" cy="90" rx="11" ry="9" :fill="m.color" :stroke="ink" stroke-width="3" />
            <circle cx="17" cy="88" r="3.5" fill="#fff" :stroke="ink" stroke-width="2" />
            <circle cx="16" cy="88" r="1.8" :fill="ink" />
        </g>

        <!-- Körper -->
        <g :stroke="ink" stroke-width="3.5">
            <path v-if="m.body === 'blob'" d="M30 128 Q20 60 80 36 Q140 60 130 128 Q118 120 108 130 Q96 120 82 130 Q68 120 56 130 Q42 120 30 128 Z" :fill="m.color" />
            <ellipse v-else-if="m.body === 'round'" cx="80" cy="84" rx="50" ry="48" :fill="m.color" />
            <path v-else-if="m.body === 'golem'" d="M34 132 L30 54 Q30 36 50 36 L110 36 Q130 36 130 54 L126 132 Z" :fill="m.color" />
            <path v-else-if="m.body === 'ghost'" d="M34 130 Q30 40 80 30 Q130 40 126 130 L114 118 L102 132 L90 118 L80 132 L70 118 L58 132 L46 118 Z" :fill="m.color" opacity=".95" />
            <g v-else-if="m.body === 'serpent'">
                <path d="M24 128 Q30 100 62 106 Q98 112 96 82 Q94 50 110 36 Q126 24 132 44 Q138 66 116 74 Q112 104 84 124 Q60 138 24 128 Z" :fill="m.color" />
                <path d="M40 120 Q60 112 80 118" fill="none" :stroke="light" stroke-width="4" />
            </g>
            <g v-else-if="m.body === 'octopus'">
                <path v-for="i in 6" :key="i" :d="`M${36 + i * 14} 110 Q${30 + i * 14} 134 ${22 + i * 16} 136`" fill="none" :stroke="ink" stroke-width="12" />
                <path v-for="i in 6" :key="`t${i}`" :d="`M${36 + i * 14} 110 Q${30 + i * 14} 134 ${22 + i * 16} 136`" fill="none" :stroke="m.color" stroke-width="7" />
                <ellipse cx="80" cy="74" rx="48" ry="44" :fill="m.color" />
            </g>
            <g v-else-if="m.body === 'dragon'">
                <path d="M20 128 Q30 104 52 110 L58 74 Q60 50 86 44 Q102 26 120 34 Q136 40 132 58 Q128 72 110 72 L104 120 Q84 136 20 128 Z" :fill="m.color" />
                <path d="M62 96 Q78 104 98 98 L96 118 Q78 124 62 116 Z" :fill="m.accent" />
            </g>
            <g v-else-if="m.body === 'troll'">
                <path d="M40 132 L36 70 Q34 30 80 28 Q126 30 124 70 L120 132 Z" :fill="m.color" />
                <path d="M36 70 Q22 72 24 90 Q32 92 38 84 Z M124 70 Q138 72 136 90 Q128 92 122 84 Z" :fill="m.color" />
            </g>
            <g v-else-if="m.body === 'king'">
                <path d="M28 134 Q24 80 46 60 L114 60 Q136 80 132 134 Z" :fill="m.accent" />
                <ellipse cx="80" cy="58" rx="34" ry="32" :fill="m.color" />
            </g>
        </g>

        <!-- Bauch / Glanz -->
        <ellipse v-if="['blob', 'round', 'golem'].includes(m.body)" cx="80" cy="106" rx="26" ry="18" :fill="m.accent" opacity=".85" />
        <ellipse v-if="!['serpent', 'dragon'].includes(m.body)" cx="58" cy="54" rx="10" ry="6" fill="#fff" opacity=".35" transform="rotate(-25 58 54)" />

        <!-- Stacheln -->
        <g v-if="m.extra === 'spikes'" :fill="m.accent" :stroke="ink" stroke-width="2.5">
            <path d="M50 44 L56 26 L64 40 Z" />
            <path d="M74 36 L80 18 L88 34 Z" />
            <path d="M98 40 L106 26 L110 44 Z" />
        </g>

        <!-- Hörner / Krone / Fühler -->
        <g v-if="m.horns === 'two'" :fill="m.accent" :stroke="ink" stroke-width="3">
            <path :d="`M${face.x - 26} ${face.y - 26} Q${face.x - 40} ${face.y - 50} ${face.x - 22} ${face.y - 52} Q${face.x - 26} ${face.y - 38} ${face.x - 14} ${face.y - 30} Z`" />
            <path :d="`M${face.x + 26} ${face.y - 26} Q${face.x + 40} ${face.y - 50} ${face.x + 22} ${face.y - 52} Q${face.x + 26} ${face.y - 38} ${face.x + 14} ${face.y - 30} Z`" />
        </g>
        <path
            v-if="m.horns === 'crown'"
            :d="`M${face.x - 24} ${face.y - 28} L${face.x - 24} ${face.y - 50} L${face.x - 12} ${face.y - 38} L${face.x} ${face.y - 56} L${face.x + 12} ${face.y - 38} L${face.x + 24} ${face.y - 50} L${face.x + 24} ${face.y - 28} Z`"
            fill="#fbbf24"
            :stroke="ink"
            stroke-width="3"
        />
        <g v-if="m.horns === 'antenna'" :stroke="ink" stroke-width="3" fill="none">
            <path :d="`M${face.x - 10} ${face.y - 30} Q${face.x - 20} ${face.y - 50} ${face.x - 28} ${face.y - 52}`" />
            <path :d="`M${face.x + 10} ${face.y - 30} Q${face.x + 20} ${face.y - 50} ${face.x + 28} ${face.y - 52}`" />
            <circle :cx="face.x - 30" :cy="face.y - 53" r="5" :fill="m.accent" />
            <circle :cx="face.x + 30" :cy="face.y - 53" r="5" :fill="m.accent" />
        </g>

        <!-- Augen -->
        <g v-for="dx in eyeXs" :key="dx">
            <ellipse :cx="face.x + dx" :cy="face.y - 8" rx="9" ry="10" fill="#fff" :stroke="ink" stroke-width="3" />
            <circle v-if="!defeated" :cx="face.x + dx + 2" :cy="face.y - 6" r="4.5" :fill="ink" />
            <path v-else :d="`M${face.x + dx - 5} ${face.y - 13} l10 10 M${face.x + dx + 5} ${face.y - 13} l-10 10`" :stroke="ink" stroke-width="3" />
            <path :d="`M${face.x + dx - 10} ${face.y - 21} L${face.x + dx + 8} ${face.y - 17}`" :stroke="ink" stroke-width="3.5" />
        </g>

        <!-- Troll-Nase zwischen Augen und Mund -->
        <ellipse v-if="m.body === 'troll'" cx="80" cy="64" rx="8" ry="7" :fill="dark" :stroke="ink" stroke-width="3" />

        <!-- Mund -->
        <g :stroke="ink" stroke-width="3">
            <template v-if="m.mouth === 'fangs'">
                <path :d="`M${face.x - 16} ${face.y + 10} Q${face.x} ${face.y + 22} ${face.x + 16} ${face.y + 10} Z`" :fill="dark" />
                <path :d="`M${face.x - 9} ${face.y + 12} l3 7 l3 -6 M${face.x + 3} ${face.y + 13} l3 6 l3 -7`" fill="#fff" stroke-width="2" />
            </template>
            <ellipse v-else-if="m.mouth === 'o'" :cx="face.x" :cy="face.y + 14" rx="7" ry="8" :fill="dark" />
            <path v-else-if="m.mouth === 'frown'" :d="`M${face.x - 14} ${face.y + 18} Q${face.x} ${face.y + 6} ${face.x + 14} ${face.y + 18}`" fill="none" />
            <path v-else :d="`M${face.x - 16} ${face.y + 8} Q${face.x} ${face.y + 24} ${face.x + 16} ${face.y + 8}`" fill="none" />
        </g>

        <!-- Staubwolken -->
        <g v-if="m.extra === 'dust'" fill="#d6d3d1" :stroke="ink" stroke-width="2" opacity=".9">
            <circle cx="22" cy="118" r="8" />
            <circle cx="32" cy="124" r="6" />
            <circle cx="138" cy="114" r="8" />
            <circle cx="128" cy="124" r="5" />
        </g>
    </svg>
</template>

<style scoped>
.monster {
    overflow: visible;
    animation: idle 2.6s ease-in-out infinite;
    transform-origin: 50% 90%;
}
.is-hit {
    animation: hit 0.5s ease-in-out;
}
.is-defeated {
    animation: none;
    filter: grayscale(0.8);
    opacity: 0.7;
    transform: rotate(-8deg) translateY(6px);
}
@keyframes idle {
    50% {
        transform: scale(1.03, 0.97);
    }
}
@keyframes hit {
    20% {
        transform: translateX(-6px) rotate(-3deg);
        filter: brightness(1.6) drop-shadow(0 0 8px #ef4444);
    }
    60% {
        transform: translateX(5px) rotate(2deg);
    }
}
@media (prefers-reduced-motion: reduce) {
    .monster,
    .is-hit {
        animation: none;
    }
}
</style>
