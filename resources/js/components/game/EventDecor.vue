<script setup lang="ts">
import { emojiUrl } from '@/lib/emoji';
import type { EventSummary } from '@/types/events';
import { computed } from 'vue';

/**
 * Event-Deko für Helden-Szene und Familienhaus (§12.7): Wimpel- oder Lichterkette in den Event-Farben,
 * die Deko-Möbel des Events am Boden, bei „Erster Schnee“ eine Schneedecke. Alles still (keine Animation),
 * damit nichts ablenkt (§11). Rein dekorativ, für Screenreader ausgeblendet.
 */
const props = withDefaults(defineProps<{ events: Pick<EventSummary, 'key' | 'icon' | 'decor'>[]; variant?: 'scene' | 'house' }>(), {
    variant: 'scene',
});

const LIGHTS = ['elf_workshop', 'lantern_festival', 'twelve_nights', 'golden_week'];
const SNOW = ['first_snow'];

const decorated = computed(() => props.events.filter((event) => event.decor));
const primary = computed(() => decorated.value[0] ?? null);
const colors = computed(() => {
    const list = primary.value?.decor?.colors ?? [];
    return list.length ? list : ['#fbbf24', '#f97316'];
});
const style = computed(() => (primary.value && LIGHTS.includes(primary.value.key) ? 'lights' : 'bunting'));
const snow = computed(() => decorated.value.some((event) => SNOW.includes(event.key)));

/** Bis zu vier Deko-Symbole aller laufenden Events, sonst das Event-Symbol. */
const icons = computed(() => {
    const all = decorated.value.flatMap((event) => (event.decor?.icons.length ? event.decor.icons : [event.icon]));
    return [...new Set(all)].slice(0, 4).map((glyph) => ({ glyph, src: emojiUrl(glyph) }));
});

const flags = Array.from({ length: 14 }, (_, i) => i);
// Durchhängende Kette: y-Versatz je Position (Parabel)
const sag = (i: number, n: number) => Math.round(18 * (1 - ((2 * i) / (n - 1) - 1) ** 2));
const positions = computed(() =>
    props.variant === 'scene' ? ['left-[3%]', 'left-[20%]', 'right-[22%]', 'right-[4%]'] : ['left-[48%]', 'left-[56%]', 'left-[40%]', 'left-[64%]'],
);
</script>

<template>
    <div v-if="primary" class="pointer-events-none absolute inset-0 select-none overflow-hidden" aria-hidden="true" :data-event-decor="primary.key">
        <!-- Leichte Tönung in der Event-Farbe -->
        <div class="absolute inset-x-0 top-0 h-1/3 opacity-25" :style="{ background: `linear-gradient(180deg, ${colors[0]}, transparent)` }" />

        <!-- Wimpelkette -->
        <svg v-if="style === 'bunting'" class="absolute inset-x-0 top-0 h-10 w-full sm:h-12" viewBox="0 0 280 44" preserveAspectRatio="none">
            <path d="M0 2 Q140 40 280 2" fill="none" stroke="#5b3a1e" stroke-width="1.2" />
            <polygon
                v-for="i in flags"
                :key="i"
                :points="`${i * 20 + 4},${3 + sag(i, flags.length) * 0.95} ${i * 20 + 16},${3 + sag(i, flags.length) * 0.95} ${i * 20 + 10},${16 + sag(i, flags.length)}`"
                :fill="colors[i % colors.length]"
                stroke="#3b2410"
                stroke-width="0.6"
            />
        </svg>

        <!-- Lichterkette (leuchtet ruhig, ohne Blinken) -->
        <svg v-else class="absolute inset-x-0 top-0 h-10 w-full sm:h-12" viewBox="0 0 280 44" preserveAspectRatio="none">
            <path d="M0 2 Q140 40 280 2" fill="none" stroke="#1f2937" stroke-width="1" />
            <g v-for="i in flags" :key="i">
                <circle :cx="i * 20 + 10" :cy="8 + sag(i, flags.length) * 0.95" r="5" :fill="colors[i % colors.length]" opacity="0.35" />
                <circle
                    :cx="i * 20 + 10"
                    :cy="8 + sag(i, flags.length) * 0.95"
                    r="2.6"
                    :fill="colors[i % colors.length]"
                    stroke="#111827"
                    stroke-width="0.4"
                />
            </g>
        </svg>

        <!-- Schneedecke -->
        <div v-if="snow" class="absolute inset-x-0 bottom-0 h-5 rounded-t-[50%] bg-white/85 shadow-[0_-2px_6px_rgb(255_255_255/0.6)]" />

        <!-- Deko-Möbel des Events -->
        <span
            v-for="(icon, i) in icons"
            :key="icon.glyph"
            class="absolute bottom-1.5 drop-shadow-[0_2px_0_rgb(0_0_0/0.25)]"
            :class="[positions[i], variant === 'scene' ? 'text-3xl sm:text-4xl' : 'text-2xl sm:text-3xl']"
        >
            <img v-if="icon.src" :src="icon.src" alt="" class="h-[1.2em] w-[1.2em]" draggable="false" />
            <template v-else>{{ icon.glyph }}</template>
        </span>
    </div>
</template>
