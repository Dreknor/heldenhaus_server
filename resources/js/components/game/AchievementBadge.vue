<script setup lang="ts">
import { shade } from '@/lib/avatar';
import { computed } from 'vue';

/**
 * Abzeichen für Erfolge (Konzept §2.9, „Abzeichen per Code“): Medaille mit Band, Farbe nach Stufe
 * (Bronze → Silber → Gold → Smaragd → Saphir → Amethyst → Rubin), Motiv aus dem Katalog.
 * Noch nicht erreichte Erfolge erscheinen ausgegraut.
 */
const props = withDefaults(defineProps<{ motif: string; tier: number; tiers?: number; size?: number }>(), { tiers: 1, size: 56 });

const TIER_COLORS = ['#cd7f32', '#cbd5e1', '#facc15', '#34d399', '#60a5fa', '#c084fc', '#fb7185'];

const ink = '#1f2937';
const locked = computed(() => props.tier < 1);
// Einstufige Erfolge sind immer golden
const color = computed(() => (locked.value ? '#d4d4d8' : props.tiers === 1 ? TIER_COLORS[2] : TIER_COLORS[Math.min(props.tier, TIER_COLORS.length) - 1]));
const uid = Math.random().toString(36).slice(2, 8);
</script>

<template>
    <span class="inline-flex shrink-0" :class="{ 'opacity-45 grayscale': locked }" :style="{ width: `${size}px`, height: `${size}px` }">
        <svg viewBox="0 0 64 64" :width="size" :height="size" xmlns="http://www.w3.org/2000/svg" stroke-linejoin="round" stroke-linecap="round" aria-hidden="true">
            <defs>
                <radialGradient :id="`m-${uid}`" cx=".35" cy=".3" r=".8">
                    <stop offset="0" :stop-color="shade(color, 0.45)" />
                    <stop offset=".55" :stop-color="color" />
                    <stop offset="1" :stop-color="shade(color, -0.25)" />
                </radialGradient>
            </defs>

            <!-- Band -->
            <g :stroke="ink" stroke-width="2.5">
                <path d="M20 2 L28 20 L22 22 Z" fill="#ef4444" />
                <path d="M44 2 L36 20 L42 22 Z" fill="#3b82f6" />
                <path d="M20 2 h10 l4 14 h-6 Z" fill="#f87171" />
                <path d="M44 2 h-10 l-4 14 h6 Z" fill="#60a5fa" />
            </g>

            <!-- Medaille -->
            <circle cx="32" cy="39" r="22" :fill="`url(#m-${uid})`" :stroke="ink" stroke-width="3" />
            <circle cx="32" cy="39" r="17" fill="none" :stroke="shade(color, -0.3)" stroke-width="1.5" stroke-dasharray="2 3" />
            <path d="M18 30 Q22 22 30 20" fill="none" stroke="#fff" stroke-width="2.5" opacity=".6" />

            <!-- Motiv -->
            <g :stroke="ink" stroke-width="2.5" fill="#fff" transform="translate(0 3)">
                <path v-if="motif === 'check'" d="M22 36 l7 7 l13 -14" fill="none" stroke-width="4.5" />
                <template v-else-if="motif === 'leaf'">
                    <path d="M22 46 Q20 28 42 26 Q44 46 22 46 Z" fill="#86efac" />
                    <path d="M22 46 Q30 36 38 30" fill="none" />
                </template>
                <path v-else-if="motif === 'flame'" d="M32 22 Q42 32 40 40 Q38 48 32 48 Q24 48 23 40 Q22 34 28 30 Q28 36 31 37 Q28 28 32 22 Z" fill="#fb923c" />
                <template v-else-if="motif === 'sun'">
                    <circle cx="32" cy="36" r="7" fill="#fde047" />
                    <path d="M32 23 v4 M32 45 v4 M19 36 h4 M41 36 h4 M23 27 l3 3 M38 42 l3 3 M41 27 l-3 3 M26 42 l-3 3" fill="none" />
                </template>
                <template v-else-if="motif === 'house'">
                    <path d="M21 36 L32 25 L43 36 V47 H21 Z" fill="#fcd34d" />
                    <path d="M29 47 V40 h6 v7" fill="#b45309" />
                </template>
                <template v-else-if="motif === 'team'">
                    <circle cx="25" cy="32" r="4.5" />
                    <circle cx="39" cy="32" r="4.5" />
                    <path d="M17 47 Q18 38 25 38 Q32 38 32 47 Z M32 47 Q32 38 39 38 Q46 38 47 47 Z" fill="#a5b4fc" />
                </template>
                <path v-else-if="motif === 'star'" d="M32 22 l4 9 l10 1 l-7.5 6.5 l2.5 9.5 l-9 -5 l-9 5 l2.5 -9.5 l-7.5 -6.5 l10 -1 Z" fill="#fde047" />
                <path v-else-if="motif === 'heart'" d="M32 47 Q18 38 20 30 Q23 23 32 29 Q41 23 44 30 Q46 38 32 47 Z" fill="#fb7185" />
                <template v-else-if="motif === 'sword'">
                    <path d="M40 22 L44 22 L44 26 L31 39 L27 35 Z" fill="#e2e8f0" />
                    <path d="M23 35 l8 8 M26 41 l-4 4" fill="none" stroke-width="3.5" />
                </template>
                <template v-else-if="motif === 'basket'">
                    <path d="M24 34 Q32 20 40 34" fill="none" stroke-width="3" />
                    <path d="M19 34 h26 l-4 13 h-18 Z" fill="#d97706" />
                    <path d="M25 38 v6 M32 38 v6 M39 38 v6" fill="none" stroke-width="1.8" />
                </template>
                <template v-else-if="motif === 'paw'">
                    <path d="M25 44 Q24 36 32 35 Q40 36 39 44 Q32 48 25 44 Z" />
                    <circle cx="23" cy="31" r="3.2" />
                    <circle cx="29" cy="26" r="3.2" />
                    <circle cx="35" cy="26" r="3.2" />
                    <circle cx="41" cy="31" r="3.2" />
                </template>
                <path v-else-if="motif === 'horseshoe'" d="M23 47 V34 Q23 24 32 24 Q41 24 41 34 V47 h-6 V35 Q35 30 32 30 Q29 30 29 35 V47 Z" fill="#cbd5e1" />
                <path v-else-if="motif === 'crown'" d="M20 44 L18 28 L26 35 L32 24 L38 35 L46 28 L44 44 Z" fill="#fde047" />
                <path v-else-if="motif === 'sparkle'" d="M32 21 Q34 34 45 36 Q34 38 32 51 Q30 38 19 36 Q30 34 32 21 Z" fill="#c4b5fd" />
                <template v-else-if="motif === 'shield'">
                    <path d="M32 22 L44 26 Q44 42 32 49 Q20 42 20 26 Z" fill="#93c5fd" />
                    <path d="M32 27 V44 M25 33 h14" fill="none" />
                </template>
                <circle v-else cx="32" cy="36" r="6" />
            </g>
        </svg>
    </span>
</template>
