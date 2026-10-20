<script setup lang="ts">
import ItemIcon from '@/components/game/ItemIcon.vue';
import { type AvatarConfig, type Equipment, type Mood, RARITY_COLORS, avatarSvg, defaultAvatar } from '@/lib/avatar';
import { computed } from 'vue';

/** Held als Brustbild (DiceBear „Toon Head“), mit Hintergrundscheibe, Klassen-Abzeichen und angelegter Ausrüstung. */
const props = withDefaults(
    defineProps<{
        avatar?: Partial<AvatarConfig> | null;
        equipment?: Equipment;
        seed?: number;
        size?: number;
        /** Näher am Gesicht und ohne Abzeichen (für kleine Anzeigen) */
        portrait?: boolean;
        classIcon?: string | null;
        mood?: Mood;
        /** Runde Hintergrundscheibe (in Szenen ausblenden) */
        backdrop?: boolean;
    }>(),
    { equipment: () => ({}), seed: 1, size: 120, portrait: false, classIcon: null, mood: 'normal', backdrop: true },
);

const a = computed<AvatarConfig>(() => ({ ...defaultAvatar(props.seed), ...(props.avatar ?? {}) }));
const svg = computed(() => avatarSvg(a.value, props.mood));

const SLOTS = ['head', 'weapon', 'shield', 'armor', 'accessory'] as const;
const gear = computed(() =>
    SLOTS.flatMap((slot) => {
        const item = props.equipment?.[slot];
        return item ? [{ slot, ...item }] : [];
    }),
);
const showBadges = computed(() => !props.portrait && props.size >= 96);
const badge = computed(() => Math.round(Math.max(22, props.size * 0.2)));
</script>

<template>
    <div
        class="hero-avatar relative shrink-0"
        :class="{ 'is-hurt': mood === 'hurt' }"
        :style="{ width: `${size}px`, height: `${size}px` }"
        role="img"
        aria-label="Avatar"
    >
        <div
            class="absolute inset-0"
            :class="backdrop ? 'overflow-hidden rounded-full shadow-inner' : ''"
            :style="backdrop ? { background: a.background } : {}"
        >
            <div class="h-full w-full [&>svg]:h-full [&>svg]:w-full" :class="portrait ? 'origin-[50%_45%] scale-[1.35]' : ''" v-html="svg" />
        </div>

        <template v-if="showBadges">
            <span
                v-if="classIcon"
                class="absolute right-0 top-0 grid place-items-center rounded-full border-2 border-white bg-white shadow"
                :style="{ width: `${badge}px`, height: `${badge}px`, fontSize: `${badge * 0.55}px` }"
                >{{ classIcon }}</span
            >
            <div v-if="gear.length" class="absolute -bottom-1 left-1/2 flex -translate-x-1/2 gap-0.5">
                <span
                    v-for="g in gear"
                    :key="g.slot"
                    class="grid place-items-center rounded-full border-2 bg-white shadow"
                    :style="{ width: `${badge}px`, height: `${badge}px`, borderColor: RARITY_COLORS[g.rarity] ?? RARITY_COLORS.common }"
                    :title="g.key"
                >
                    <ItemIcon :item="{ key: g.key, type: 'equipment', slot: g.slot, rarity: g.rarity }" :size="badge - 6" />
                </span>
            </div>
        </template>
    </div>
</template>

<style scoped>
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
