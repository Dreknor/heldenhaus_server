<script setup lang="ts">
import { computed } from 'vue';

/**
 * Visueller Timer nach dem Vorbild des Time Timers (§11.4): eine farbige Scheibe, die schrumpft.
 * Ziffern nur, wenn die Person sie in den Fokus-Einstellungen gewählt hat.
 */
const props = withDefaults(defineProps<{ remaining: number; total: number; size?: number; digits?: boolean }>(), {
    size: 160,
    digits: false,
});

const fraction = computed(() => (props.total > 0 ? Math.max(0, Math.min(1, props.remaining / props.total)) : 0));
// Farbe wandert sanft von Grün über Gelb nach Orange, nie Alarm-Rot
const hue = computed(() => 30 + fraction.value * 110);
const wedge = computed(() => {
    const f = fraction.value;
    if (f >= 0.9999) return null;
    const a = f * Math.PI * 2;
    const x = 50 + 42 * Math.sin(a);
    const y = 50 - 42 * Math.cos(a);
    return `M50 50 L50 8 A42 42 0 ${f > 0.5 ? 1 : 0} 1 ${x.toFixed(2)} ${y.toFixed(2)} Z`;
});
const label = computed(() => {
    const m = Math.floor(props.remaining / 60);
    const s = props.remaining % 60;
    return `${m}:${s.toString().padStart(2, '0')}`;
});
const minutesLeft = computed(() => Math.ceil(props.remaining / 60));
</script>

<template>
    <div
        class="relative inline-flex items-center justify-center"
        :style="{ width: `${size}px`, height: `${size}px` }"
        role="timer"
        :aria-label="`noch ${minutesLeft} Minuten`"
    >
        <svg viewBox="0 0 100 100" class="size-full drop-shadow-md">
            <defs>
                <radialGradient id="vt-face" cx="50%" cy="40%" r="60%">
                    <stop offset="0%" stop-color="#ffffff" />
                    <stop offset="100%" stop-color="#e2e8f0" />
                </radialGradient>
            </defs>
            <circle cx="50" cy="50" r="49" fill="#334155" />
            <circle cx="50" cy="50" r="46" fill="url(#vt-face)" />
            <g stroke="#94a3b8" stroke-width="1.2" stroke-linecap="round">
                <line v-for="i in 12" :key="i" x1="50" y1="5.5" x2="50" :y2="i % 3 === 0 ? 10 : 8" :transform="`rotate(${i * 30} 50 50)`" />
            </g>
            <circle v-if="fraction >= 0.9999" cx="50" cy="50" r="42" :fill="`hsl(${hue} 75% 52%)`" />
            <path v-else-if="wedge" :d="wedge" :fill="`hsl(${hue} 75% 52%)`" class="transition-[fill] duration-1000" />
            <circle cx="50" cy="50" r="5" fill="#334155" />
            <circle cx="50" cy="50" r="2" fill="#f8fafc" />
        </svg>
        <span
            v-if="digits"
            class="absolute bottom-[18%] rounded-md bg-slate-900/80 px-1.5 py-0.5 font-mono text-sm font-bold tabular-nums text-white"
            >{{ label }}</span
        >
    </div>
</template>
