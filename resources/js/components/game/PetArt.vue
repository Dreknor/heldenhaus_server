<script setup lang="ts">
import { computed, ref } from 'vue';

/**
 * Haustiere (Konzept §2.9): Bild der Tierart (Fluent Emoji 3D, public/images/pets) mit einem Schimmer
 * in der Farbe des Schlüpfpulvers. Fehlt ein Bild, zeichnet die SVG-Vorlage das Tier.
 * Reittiere sind größer und tragen einen Sattel.
 */
const props = withDefaults(defineProps<{ egg: string; potion: string; mount?: boolean; size?: number }>(), { mount: false, size: 96 });

const ink = '#1f2937';
// Farben der Schlüpfpulver (wie stats.palette in database/content/items.json)
const PALETTES: Record<string, { main: string; belly: string }> = {
    potion_fire: { main: '#f97316', belly: '#fde047' },
    potion_ice: { main: '#7dd3fc', belly: '#f0f9ff' },
    potion_forest: { main: '#4d7c0f', belly: '#d9f99d' },
    potion_stone: { main: '#78716c', belly: '#e7e5e4' },
    potion_ocean: { main: '#0f766e', belly: '#99f6e4' },
    potion_candy: { main: '#f472b6', belly: '#fce7f3' },
    potion_golden: { main: '#eab308', belly: '#fef9c3' },
    potion_shadow: { main: '#312e81', belly: '#a5b4fc' },
    potion_spooky: { main: '#ea580c', belly: '#581c87' },
    potion_frost_star: { main: '#e0f2fe', belly: '#dc2626' },
    potion_pastel: { main: '#c4b5fd', belly: '#fef08a' },
    potion_sunny: { main: '#f59e0b', belly: '#fde68a' },
    potion_mint: { main: '#34d399', belly: '#d1fae5' },
    potion_berry: { main: '#7c3aed', belly: '#ede9fe' },
    potion_coral: { main: '#fb7185', belly: '#ffe4e6' },
    potion_cloud: { main: '#e2e8f0', belly: '#ffffff' },
    potion_autumn: { main: '#c2410c', belly: '#fed7aa' },
    potion_night_sky: { main: '#1e3a8a', belly: '#fde047' },
    potion_crystal: { main: '#67e8f9', belly: '#f0abfc' },
    potion_rainbow: { main: 'url(#rainbow)', belly: '#fef3c7' },
};
const p = computed(() => PALETTES[props.potion] ?? { main: '#a3a3a3', belly: '#f5f5f4' });
const species = computed(() => props.egg.replace(/^egg_/, ''));
const uid = Math.random().toString(36).slice(2, 8);
const imageFailed = ref(false);
const width = computed(() => (props.mount ? props.size * 1.3 : props.size));
const glow = computed(() =>
    props.potion === 'potion_rainbow'
        ? 'conic-gradient(from 0deg, #f87171, #facc15, #4ade80, #818cf8, #f87171)'
        : `radial-gradient(circle, ${p.value.main} 0%, ${p.value.belly} 45%, transparent 70%)`,
);
</script>

<template>
    <span
        v-if="!imageFailed"
        class="pet pet-image"
        role="img"
        :aria-label="`Haustier ${species}`"
        :style="{ width: `${width}px`, height: `${width * 0.92}px` }"
    >
        <span class="pet-glow" :style="{ background: glow }" />
        <img :src="`/images/pets/${species}.webp`" alt="" loading="lazy" @error="imageFailed = true" />
        <span v-if="mount" class="pet-saddle" aria-hidden="true" />
    </span>
    <svg
        v-else
        viewBox="0 0 120 110"
        :width="mount ? size * 1.3 : size"
        :height="(mount ? size * 1.3 : size) * 0.92"
        xmlns="http://www.w3.org/2000/svg"
        role="img"
        :aria-label="`Haustier ${species}`"
        stroke-linejoin="round"
        stroke-linecap="round"
        class="pet"
    >
        <defs>
            <linearGradient :id="`rainbow-${uid}`" x1="0" x2="1" y1="0" y2="1">
                <stop offset="0" stop-color="#f87171" />
                <stop offset=".3" stop-color="#facc15" />
                <stop offset=".6" stop-color="#4ade80" />
                <stop offset="1" stop-color="#818cf8" />
            </linearGradient>
        </defs>
        <ellipse cx="60" cy="104" rx="34" ry="4.5" fill="#000" opacity=".15" />

        <g :fill="p.main === 'url(#rainbow)' ? `url(#rainbow-${uid})` : p.main" :stroke="ink" stroke-width="3">
            <!-- Wolf -->
            <template v-if="species === 'wolf'">
                <path d="M92 70 Q112 58 110 40 Q100 56 86 62 Z" />
                <ellipse cx="62" cy="76" rx="30" ry="20" />
                <path d="M38 92 v10 M50 94 v9 M72 94 v9 M84 92 v10" stroke-width="7" />
                <path d="M22 42 L28 16 L42 34 Z M58 34 L70 14 L74 42 Z" />
                <ellipse cx="48" cy="48" rx="26" ry="22" />
                <path d="M30 56 Q36 70 50 68 Q60 66 62 58" :fill="p.belly" stroke-width="2.5" />
                <ellipse cx="44" cy="58" rx="5" ry="3.5" :fill="ink" stroke-width="0" />
            </template>
            <!-- Katze -->
            <template v-else-if="species === 'cat'">
                <path d="M88 82 Q112 80 108 54 Q104 46 98 52 Q102 70 86 72 Z" />
                <ellipse cx="62" cy="80" rx="28" ry="20" />
                <path d="M42 94 v8 M54 96 v7 M70 96 v7 M82 94 v8" stroke-width="7" />
                <path d="M30 34 L32 12 L48 28 Z M66 28 L82 12 L84 34 Z" />
                <circle cx="56" cy="46" r="26" />
                <ellipse cx="56" cy="56" rx="12" ry="8" :fill="p.belly" stroke-width="2.5" />
                <path d="M53 52 l3 3 l3 -3 Z" fill="#f472b6" stroke-width="1.5" />
                <path d="M40 56 h-14 M40 60 l-13 4 M72 56 h14 M72 60 l13 4" stroke-width="1.8" />
            </template>
            <!-- Eule -->
            <template v-else-if="species === 'owl'">
                <ellipse cx="60" cy="62" rx="34" ry="38" />
                <ellipse cx="60" cy="78" rx="20" ry="18" :fill="p.belly" stroke-width="2.5" />
                <path d="M28 36 L32 18 L46 30 Z M74 30 L88 18 L92 36 Z" />
                <path d="M28 66 Q16 76 26 92 Q34 84 34 72 Z M92 66 Q104 76 94 92 Q86 84 86 72 Z" />
                <path d="M54 52 L60 62 L66 52 Z" fill="#f59e0b" stroke-width="2.5" />
                <path d="M48 98 v6 M72 98 v6" stroke="#f59e0b" stroke-width="5" />
            </template>
            <!-- Drache -->
            <template v-else>
                <path d="M30 60 Q4 40 10 74 Q20 66 32 72 Z M90 60 Q116 40 110 74 Q100 66 88 72 Z" />
                <path d="M86 86 Q108 92 112 74 Q104 80 90 76 Z" />
                <ellipse cx="60" cy="78" rx="28" ry="22" />
                <ellipse cx="60" cy="84" rx="16" ry="14" :fill="p.belly" stroke-width="2.5" />
                <path d="M44 98 v6 M76 98 v6" stroke-width="7" />
                <path d="M42 28 L40 10 L52 24 Z M68 24 L80 10 L78 28 Z" :fill="p.belly" />
                <ellipse cx="60" cy="40" rx="26" ry="20" />
                <path d="M46 52 Q60 60 74 52" fill="none" stroke-width="2.5" />
                <circle cx="52" cy="47" r="1.6" :fill="ink" stroke-width="0" />
                <circle cx="68" cy="47" r="1.6" :fill="ink" stroke-width="0" />
            </template>
        </g>

        <!-- Augen (gemeinsam, je Art leicht versetzt) -->
        <g :fill="ink">
            <template v-if="species === 'owl'">
                <circle cx="46" cy="44" r="11" fill="#fff" :stroke="ink" stroke-width="3" />
                <circle cx="74" cy="44" r="11" fill="#fff" :stroke="ink" stroke-width="3" />
                <circle cx="47" cy="45" r="5" />
                <circle cx="73" cy="45" r="5" />
            </template>
            <template v-else-if="species === 'wolf'">
                <circle cx="40" cy="42" r="3.5" />
                <circle cx="58" cy="42" r="3.5" />
            </template>
            <template v-else-if="species === 'cat'">
                <ellipse cx="46" cy="42" rx="3.5" ry="5" />
                <ellipse cx="66" cy="42" rx="3.5" ry="5" />
            </template>
            <template v-else>
                <circle cx="50" cy="36" r="4" />
                <circle cx="70" cy="36" r="4" />
                <circle cx="51" cy="35" r="1.3" fill="#fff" />
                <circle cx="71" cy="35" r="1.3" fill="#fff" />
            </template>
        </g>

        <!-- Sattel für Reittiere -->
        <g v-if="mount" :stroke="ink" stroke-width="2.5" :transform="species === 'owl' ? 'translate(0 18)' : undefined">
            <path d="M48 62 Q62 54 78 62 L76 76 Q62 70 50 76 Z" fill="#b91c1c" />
            <path d="M62 74 v14" stroke="#78350f" stroke-width="3" />
            <circle cx="62" cy="90" r="3" fill="#fbbf24" />
        </g>
    </svg>
</template>

<style scoped>
.pet-image {
    position: relative;
    display: inline-block;
}
.pet-glow {
    position: absolute;
    inset: 12% 8% 0;
    border-radius: 9999px;
    opacity: 0.55;
    filter: blur(6px);
}
.pet-image img {
    position: relative;
    width: 100%;
    height: 100%;
    object-fit: contain;
}
.pet-saddle {
    position: absolute;
    left: 36%;
    top: 46%;
    width: 28%;
    height: 12%;
    border: 2px solid #1f2937;
    border-radius: 40% 40% 30% 30%;
    background: #b91c1c;
}
.pet {
    overflow: visible;
    animation: bob 2.2s ease-in-out infinite;
}
@keyframes bob {
    50% {
        transform: translateY(-3px);
    }
}
@media (prefers-reduced-motion: reduce) {
    .pet {
        animation: none;
    }
}
</style>
