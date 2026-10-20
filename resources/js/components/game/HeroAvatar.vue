<script setup lang="ts">
import { type AvatarConfig, type Equipment, RARITY_COLORS, defaultAvatar, shade } from '@/lib/avatar';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        avatar?: Partial<AvatarConfig> | null;
        equipment?: Equipment;
        seed?: number;
        size?: number;
        /** Kopf und Oberkörper statt ganzer Figur (für kleine Anzeigen) */
        portrait?: boolean;
        classIcon?: string | null;
        mood?: 'normal' | 'happy' | 'hurt';
    }>(),
    { equipment: () => ({}), seed: 1, size: 120, portrait: false, classIcon: null, mood: 'normal' },
);

const a = computed<AvatarConfig>(() => ({ ...defaultAvatar(props.seed), ...(props.avatar ?? {}) }));
const ink = '#1f2937';
const skinShade = computed(() => shade(a.value.skin, -0.12));
const hairShade = computed(() => shade(a.value.hair_color, -0.25));
const eq = computed(() => props.equipment ?? {});
const color = (slot: keyof Equipment) => RARITY_COLORS[eq.value[slot]?.rarity ?? 'common'];
const viewBox = computed(() => (props.portrait ? '14 8 72 72' : '0 0 100 120'));
const height = computed(() => (props.portrait ? props.size : props.size * 1.2));
</script>

<template>
    <svg
        :viewBox="viewBox"
        :width="size"
        :height="height"
        xmlns="http://www.w3.org/2000/svg"
        role="img"
        aria-label="Avatar"
        stroke-linejoin="round"
        stroke-linecap="round"
        class="hero-avatar"
        :class="{ 'is-hurt': mood === 'hurt' }"
    >
        <!-- Hintergrund -->
        <circle cx="50" cy="58" r="47" :fill="a.background" />
        <ellipse cx="50" cy="113" rx="24" ry="4" fill="#000" opacity=".12" />

        <!-- Haare hinten (lang, Zopf, Dutt) -->
        <path v-if="a.hair === 'long'" d="M27 40 Q24 74 32 82 L68 82 Q76 74 73 40 Z" :fill="a.hair_color" :stroke="ink" stroke-width="2.5" />
        <path v-if="a.hair === 'ponytail'" d="M70 34 Q88 40 82 66 Q80 72 74 70 Q80 54 68 44 Z" :fill="a.hair_color" :stroke="ink" stroke-width="2.5" />
        <circle v-if="a.hair === 'bun'" cx="50" cy="17" r="9" :fill="a.hair_color" :stroke="ink" stroke-width="2.5" />

        <!-- Beine -->
        <rect x="36" y="94" width="11" height="17" rx="4" :fill="shade(a.outfit, -0.35)" :stroke="ink" stroke-width="2.5" />
        <rect x="53" y="94" width="11" height="17" rx="4" :fill="shade(a.outfit, -0.35)" :stroke="ink" stroke-width="2.5" />
        <path d="M33 111 h15 v3 h-15 Z M52 111 h15 v3 h-15 Z" :fill="ink" />

        <!-- Schild (linke Hand, hinter dem Arm) -->
        <g v-if="eq.shield">
            <template v-if="eq.shield.key === 'shield_lid'">
                <circle cx="22" cy="80" r="13" fill="#cbd5e1" :stroke="ink" stroke-width="2.5" />
                <circle cx="22" cy="80" r="8" fill="none" stroke="#94a3b8" stroke-width="2" />
                <circle cx="22" cy="80" r="3" fill="#64748b" :stroke="ink" stroke-width="1.5" />
            </template>
            <template v-else>
                <path d="M10 70 h24 v10 Q34 94 22 98 Q10 94 10 80 Z" :fill="color('shield')" :stroke="ink" stroke-width="2.5" />
                <path d="M22 72 v24 M12 80 h20" stroke="#fff" stroke-width="2" opacity=".7" />
            </template>
        </g>

        <!-- Körper / Kleidung -->
        <path d="M31 72 Q31 64 40 64 L60 64 Q69 64 69 72 L69 96 L31 96 Z" :fill="a.outfit" :stroke="ink" stroke-width="2.5" />
        <path d="M44 64 L50 72 L56 64" fill="none" :stroke="shade(a.outfit, -0.3)" stroke-width="2" />

        <!-- Rüstung -->
        <g v-if="eq.armor">
            <template v-if="eq.armor.key === 'armor_hoodie'">
                <path d="M30 72 Q30 63 40 63 L60 63 Q70 63 70 72 L70 97 L30 97 Z" fill="#7c3aed" :stroke="ink" stroke-width="2.5" />
                <path d="M41 84 h18 v8 h-18 Z" fill="#6d28d9" :stroke="ink" stroke-width="1.8" />
                <path d="M46 64 v10 M54 64 v10" stroke="#f5f3ff" stroke-width="1.8" />
            </template>
            <template v-else>
                <path d="M32 70 Q32 64 40 64 L60 64 Q68 64 68 70 L66 92 L34 92 Z" :fill="color('armor')" :stroke="ink" stroke-width="2.5" />
                <circle v-for="x in [40, 50, 60]" :key="x" :cx="x" cy="72" r="1.6" fill="#fff" opacity=".8" />
            </template>
        </g>

        <!-- Arme und Hände -->
        <path d="M31 70 Q22 76 24 88" fill="none" :stroke="ink" stroke-width="9" />
        <path d="M31 70 Q22 76 24 88" fill="none" :stroke="eq.armor?.key === 'armor_hoodie' ? '#7c3aed' : a.outfit" stroke-width="5.5" />
        <path d="M69 70 Q78 76 76 88" fill="none" :stroke="ink" stroke-width="9" />
        <path d="M69 70 Q78 76 76 88" fill="none" :stroke="eq.armor?.key === 'armor_hoodie' ? '#7c3aed' : a.outfit" stroke-width="5.5" />
        <circle cx="24" cy="89" r="4.5" :fill="a.skin" :stroke="ink" stroke-width="2.2" />

        <!-- Waffe (rechte Hand) -->
        <g v-if="eq.weapon">
            <template v-if="eq.weapon.key === 'weapon_wooden_sword'">
                <path d="M78 86 L90 52 L94 54 L82 88 Z" fill="#c08a52" :stroke="ink" stroke-width="2.2" />
                <path d="M72 84 L86 90" :stroke="ink" stroke-width="5" />
                <path d="M72 84 L86 90" stroke="#8b5a2b" stroke-width="2.5" />
            </template>
            <template v-else>
                <path d="M78 86 L92 48 L96 50 L82 88 Z" :fill="color('weapon')" :stroke="ink" stroke-width="2.2" />
                <path d="M72 84 L86 90" :stroke="ink" stroke-width="5" />
                <path d="M72 84 L86 90" stroke="#fbbf24" stroke-width="2.5" />
            </template>
        </g>
        <circle cx="76" cy="89" r="4.5" :fill="a.skin" :stroke="ink" stroke-width="2.2" />

        <!-- Kopf -->
        <circle cx="29" cy="45" r="4" :fill="skinShade" :stroke="ink" stroke-width="2.2" />
        <circle cx="71" cy="45" r="4" :fill="skinShade" :stroke="ink" stroke-width="2.2" />
        <circle cx="50" cy="42" r="21" :fill="a.skin" :stroke="ink" stroke-width="2.5" />

        <!-- Haare vorne -->
        <path v-if="a.hair === 'short'" d="M29 40 Q30 20 50 20 Q70 20 71 40 Q62 30 50 33 Q40 30 29 40 Z" :fill="a.hair_color" :stroke="ink" stroke-width="2.5" />
        <path
            v-if="a.hair === 'spiky'"
            d="M29 41 L28 28 L36 31 L38 19 L46 27 L52 16 L57 27 L65 20 L66 31 L73 29 L71 41 Q60 31 50 32 Q40 31 29 41 Z"
            :fill="a.hair_color"
            :stroke="ink"
            stroke-width="2.5"
        />
        <g v-if="a.hair === 'curly'" :fill="a.hair_color" :stroke="ink" stroke-width="2.3">
            <circle v-for="(c, i) in [[32, 34], [38, 25], [47, 21], [56, 22], [64, 27], [69, 35]]" :key="i" :cx="c[0]" :cy="c[1]" r="8" />
        </g>
        <path
            v-if="['long', 'ponytail', 'bun'].includes(a.hair)"
            d="M29 42 Q29 21 50 21 Q71 21 71 42 Q66 31 56 30 Q52 36 44 34 Q36 33 29 42 Z"
            :fill="a.hair_color"
            :stroke="ink"
            stroke-width="2.5"
        />
        <path v-if="a.hair !== 'bald'" d="M40 25 Q47 22 54 24" fill="none" :stroke="hairShade" stroke-width="2" opacity=".7" />

        <!-- Gesicht -->
        <g :fill="ink" :stroke="ink">
            <template v-if="a.eyes === 'happy' || mood === 'happy'">
                <path d="M39 44 Q42.5 40 46 44 M54 44 Q57.5 40 61 44" fill="none" stroke-width="2.4" />
            </template>
            <template v-else-if="a.eyes === 'sleepy'">
                <path d="M39 44 h7 M54 44 h7" stroke-width="2.4" />
            </template>
            <template v-else-if="a.eyes === 'wink'">
                <circle cx="42.5" cy="44" r="3" stroke-width="0" />
                <path d="M54 44 Q57.5 40 61 44" fill="none" stroke-width="2.4" />
            </template>
            <template v-else>
                <circle cx="42.5" cy="44" r="3" stroke-width="0" />
                <circle cx="57.5" cy="44" r="3" stroke-width="0" />
                <circle cx="43.5" cy="43" r="1" fill="#fff" stroke-width="0" />
                <circle cx="58.5" cy="43" r="1" fill="#fff" stroke-width="0" />
            </template>
        </g>
        <circle cx="37" cy="51" r="3" fill="#f87171" opacity=".35" />
        <circle cx="63" cy="51" r="3" fill="#f87171" opacity=".35" />
        <path v-if="mood === 'hurt'" d="M45 55 Q50 51 55 55" fill="none" :stroke="ink" stroke-width="2.4" />
        <path v-else d="M44 52 Q50 58 56 52" fill="none" :stroke="ink" stroke-width="2.4" />

        <!-- Accessoire -->
        <g v-if="eq.accessory">
            <template v-if="eq.accessory.key === 'accessory_glasses'">
                <circle cx="42.5" cy="44" r="6" fill="#bfdbfe" fill-opacity=".35" :stroke="ink" stroke-width="2.2" />
                <circle cx="57.5" cy="44" r="6" fill="#bfdbfe" fill-opacity=".35" :stroke="ink" stroke-width="2.2" />
                <path d="M48.5 44 h3" :stroke="ink" stroke-width="2.2" />
            </template>
            <template v-else>
                <path d="M43 64 Q50 72 57 64" fill="none" stroke="#fbbf24" stroke-width="1.8" />
                <circle cx="50" cy="71" r="3.5" :fill="color('accessory')" :stroke="ink" stroke-width="1.8" />
            </template>
        </g>

        <!-- Kopfbedeckung -->
        <g v-if="eq.head">
            <template v-if="eq.head.key === 'head_cap'">
                <path d="M29 34 Q30 17 50 17 Q70 17 71 34 Z" fill="#ef4444" :stroke="ink" stroke-width="2.5" />
                <path d="M54 34 Q72 33 80 37 Q70 40 54 38 Z" fill="#dc2626" :stroke="ink" stroke-width="2.3" />
                <circle cx="50" cy="17" r="2.5" fill="#fff" :stroke="ink" stroke-width="1.5" />
            </template>
            <template v-else>
                <path d="M27 38 Q27 15 50 15 Q73 15 73 38 L66 36 Q50 30 34 36 Z" :fill="color('head')" :stroke="ink" stroke-width="2.5" />
                <path d="M50 15 v-6" :stroke="ink" stroke-width="2.5" />
                <circle cx="50" cy="8" r="3" fill="#fbbf24" :stroke="ink" stroke-width="1.8" />
            </template>
        </g>

        <!-- Klassen-Abzeichen -->
        <g v-if="classIcon && !portrait">
            <circle cx="85" cy="18" r="11" fill="#fff" :stroke="ink" stroke-width="2.2" />
            <text x="85" y="22.5" text-anchor="middle" font-size="12">{{ classIcon }}</text>
        </g>
    </svg>
</template>

<style scoped>
.hero-avatar {
    overflow: visible;
}
.is-hurt {
    animation: hurt 0.45s ease-in-out;
}
@keyframes hurt {
    25% {
        transform: translateX(-3px);
        filter: drop-shadow(0 0 6px #ef4444);
    }
    75% {
        transform: translateX(3px);
    }
}
</style>
