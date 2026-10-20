<script setup lang="ts">
import { computed } from 'vue';

/** Sammel-Quest: ein Korb, der sich mit dem Fortschritt füllt. */
const props = withDefaults(defineProps<{ icon?: string | null; ratio?: number; size?: number }>(), { icon: '⭐', ratio: 0, size: 120 });

const ink = '#1f2937';
const fill = computed(() => Math.max(0, Math.min(1, props.ratio)));
// Gegenstände, die mit dem Fortschritt im Korb sichtbar werden
const items = computed(() =>
    [
        [52, 62],
        [80, 58],
        [108, 62],
        [64, 46],
        [96, 46],
        [80, 34],
    ].slice(0, Math.max(1, Math.round(fill.value * 6))),
);
</script>

<template>
    <svg viewBox="0 0 160 150" :width="size" :height="size * 0.94" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Sammel-Quest" stroke-linejoin="round" class="overflow-visible">
        <ellipse cx="80" cy="140" rx="50" ry="6" fill="#000" opacity=".15" />
        <text v-for="([x, y], i) in items" :key="i" :x="x" :y="y" text-anchor="middle" font-size="26">{{ icon }}</text>
        <path d="M28 70 L132 70 L120 134 Q118 138 112 138 L48 138 Q42 138 40 134 Z" fill="#d97706" :stroke="ink" stroke-width="3.5" />
        <path d="M34 90 L126 90 M38 110 L122 110" stroke="#92400e" stroke-width="3" />
        <path d="M60 72 L56 136 M80 72 L80 136 M100 72 L104 136" stroke="#92400e" stroke-width="3" />
        <path d="M24 64 h112 a4 4 0 0 1 0 8 h-112 a4 4 0 0 1 0 -8 Z" fill="#f59e0b" :stroke="ink" stroke-width="3" />
        <path d="M44 66 Q80 6 116 66" fill="none" :stroke="ink" stroke-width="7" />
        <path d="M44 66 Q80 6 116 66" fill="none" stroke="#f59e0b" stroke-width="3.5" />
    </svg>
</template>
