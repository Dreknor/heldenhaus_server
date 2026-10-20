<script setup lang="ts">
import HeroAvatar from '@/components/game/HeroAvatar.vue';
import PetArt from '@/components/game/PetArt.vue';
import type { Appearance } from '@/types';
import { computed } from 'vue';

/** Szene im Heldenpanel (Konzept §2.9, G2): Himmel nach Tageszeit, Heldenhaus, Avatar und Begleiter. */
defineProps<{
    appearance: Appearance;
    companion: { egg_key: string; potion_key: string; is_mount: boolean; name: string } | null;
    hurt?: boolean;
}>();

const hour = new Date().getHours();
const phase = computed(() => (hour < 6 || hour >= 21 ? 'night' : hour < 9 ? 'morning' : hour >= 18 ? 'evening' : 'day'));
const sky = computed(
    () =>
        ({
            night: ['#0f172a', '#312e81'],
            morning: ['#fda4af', '#fde68a'],
            day: ['#7dd3fc', '#e0f2fe'],
            evening: ['#7c3aed', '#fb923c'],
        })[phase.value],
);
const ink = '#1f2937';
const stars = [
    [40, 20],
    [110, 34],
    [190, 16],
    [260, 40],
    [330, 22],
    [420, 30],
    [500, 14],
    [560, 38],
];
</script>

<template>
    <div class="relative overflow-hidden rounded-2xl border">
        <svg class="absolute inset-0 h-full w-full" viewBox="0 0 600 180" preserveAspectRatio="xMidYMax slice" aria-hidden="true">
            <defs>
                <linearGradient id="scene-sky" x1="0" x2="0" y1="0" y2="1">
                    <stop offset="0" :stop-color="sky[0]" />
                    <stop offset="1" :stop-color="sky[1]" />
                </linearGradient>
            </defs>
            <rect width="600" height="180" fill="url(#scene-sky)" />

            <!-- Sonne / Mond / Sterne -->
            <template v-if="phase === 'night'">
                <circle v-for="([x, y], i) in stars" :key="i" :cx="x" :cy="y" r="1.8" fill="#fef9c3" class="twinkle" :style="{ animationDelay: `${i * 0.4}s` }" />
                <circle cx="520" cy="44" r="18" fill="#fef9c3" />
                <circle cx="528" cy="38" r="16" :fill="sky[0]" />
            </template>
            <circle v-else :cx="phase === 'evening' ? 470 : phase === 'morning' ? 90 : 500" :cy="phase === 'day' ? 40 : 70" r="22" fill="#fde047" :stroke="ink" stroke-width="3" />

            <!-- Wolken -->
            <g v-if="phase !== 'night'" fill="#fff" opacity=".85" class="clouds">
                <ellipse cx="180" cy="40" rx="30" ry="11" />
                <ellipse cx="200" cy="32" rx="20" ry="11" />
                <ellipse cx="380" cy="56" rx="26" ry="9" />
            </g>

            <!-- Hügel -->
            <path d="M0 130 Q120 90 250 125 T600 110 V180 H0 Z" :fill="phase === 'night' ? '#14532d' : '#86efac'" :stroke="ink" stroke-width="3" />
            <path d="M0 150 Q160 120 320 150 T600 140 V180 H0 Z" :fill="phase === 'night' ? '#166534' : '#4ade80'" :stroke="ink" stroke-width="3" />

            <!-- Das Heldenhaus -->
            <g transform="translate(470 78)" :stroke="ink" stroke-width="3">
                <rect x="0" y="30" width="70" height="46" fill="#fde68a" />
                <path d="M-8 32 L35 0 L78 32 Z" fill="#ef4444" />
                <rect x="28" y="50" width="16" height="26" fill="#92400e" />
                <rect x="8" y="40" width="14" height="12" :fill="phase === 'night' || phase === 'evening' ? '#fde047' : '#bae6fd'" />
                <rect x="50" y="40" width="14" height="12" :fill="phase === 'night' || phase === 'evening' ? '#fde047' : '#bae6fd'" />
                <path d="M54 12 v-12 h10 v20" fill="#a8a29e" />
            </g>

            <!-- Bäume -->
            <g :stroke="ink" stroke-width="3">
                <rect x="38" y="112" width="8" height="26" fill="#92400e" />
                <circle cx="42" cy="104" r="20" :fill="phase === 'night' ? '#15803d' : '#22c55e'" />
                <rect x="420" y="122" width="6" height="18" fill="#92400e" />
                <circle cx="423" cy="116" r="13" :fill="phase === 'night' ? '#15803d' : '#22c55e'" />
            </g>
        </svg>

        <div class="relative flex min-h-44 items-end gap-2 px-4 pt-4 pb-2 sm:px-8">
            <HeroAvatar
                :avatar="appearance.avatar"
                :seed="appearance.seed"
                :equipment="appearance.equipment"
                :class-icon="appearance.class_icon"
                :mood="hurt ? 'hurt' : 'happy'"
                :backdrop="false"
                :size="112"
                class="shrink-0 drop-shadow"
            />
            <PetArt v-if="companion" :egg="companion.egg_key" :potion="companion.potion_key" :mount="companion.is_mount" :size="64" class="mb-1 shrink-0" />
            <div class="mb-2 ml-auto max-w-[55%] text-right sm:mr-24">
                <slot />
            </div>
        </div>
    </div>
</template>

<style scoped>
.twinkle {
    animation: twinkle 2.4s ease-in-out infinite;
}
.clouds {
    animation: drift 30s linear infinite alternate;
}
@keyframes twinkle {
    50% {
        opacity: 0.3;
    }
}
@keyframes drift {
    to {
        transform: translateX(40px);
    }
}
@media (prefers-reduced-motion: reduce) {
    .twinkle,
    .clouds {
        animation: none;
    }
}
</style>
