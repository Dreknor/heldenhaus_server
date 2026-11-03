<script setup lang="ts">
import HeroAvatar from '@/components/game/HeroAvatar.vue';
import PixelYard from '@/components/game/PixelYard.vue';
import { computed } from 'vue';

/**
 * Pixel-Hof mit einer kleinen Heldenfamilie für Seiten ohne Anmeldung (Willkommen, Login).
 * Die Figuren entstehen aus festen Startwerten des Avatar-Baukastens, die Tiere sind die Fluent-Haustiere.
 */
const props = withDefaults(defineProps<{ heroSize?: number; pets?: boolean }>(), { heroSize: 112, pets: true });

const hour = new Date().getHours();
const phase = computed(() => (hour < 6 || hour >= 21 ? 'night' : hour < 9 ? 'morning' : hour >= 18 ? 'evening' : 'day'));
const sky = computed(
    () =>
        ({
            night: 'linear-gradient(180deg, #0b1026 0%, #1e1b4b 55%, #3b2f6b 100%)',
            morning: 'linear-gradient(180deg, #fbc2c8 0%, #fde2b6 55%, #fef3c7 100%)',
            day: 'linear-gradient(180deg, #4fb3f6 0%, #8fd3fe 55%, #d6f0ff 100%)',
            evening: 'linear-gradient(180deg, #5b3a9e 0%, #c2549a 50%, #fb9a5b 100%)',
        })[phase.value],
);
const family = [
    { seed: 7, scale: 1 },
    { seed: 21, scale: 0.78 },
    { seed: 3, scale: 0.68 },
    { seed: 42, scale: 0.96 },
];
const heroes = computed(() => family.map((f) => ({ ...f, size: Math.round(props.heroSize * f.scale) })));
</script>

<template>
    <div class="hh-panel relative overflow-hidden border-white/60" :style="{ background: sky }">
        <PixelYard :phase="phase" />
        <div class="relative z-[2] flex h-full min-h-[inherit] items-end gap-0 px-3 sm:px-6">
            <HeroAvatar
                v-for="h in heroes"
                :key="h.seed"
                :seed="h.seed"
                :size="h.size"
                :backdrop="false"
                mood="happy"
                class="-mb-1 -mr-3 drop-shadow-lg"
            />
            <img v-if="pets" src="/images/pets/dog.webp" alt="" class="mb-2 ml-2 w-12 drop-shadow-md sm:w-14" />
            <img v-if="pets" src="/images/pets/cat.webp" alt="" class="mb-2 hidden w-10 drop-shadow-md sm:block sm:w-12" />
            <div class="ml-auto self-center">
                <slot />
            </div>
        </div>
    </div>
</template>
