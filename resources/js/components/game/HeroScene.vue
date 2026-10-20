<script setup lang="ts">
import HeroAvatar from '@/components/game/HeroAvatar.vue';
import PetArt from '@/components/game/PetArt.vue';
import type { Appearance } from '@/types';
import { computed } from 'vue';

/**
 * Szene im Heldenpanel (Konzept §2.9, G2): Himmel nach Tageszeit, Heldenhaus, Avatar und Begleiter.
 * Landschaftsgrafiken: Microsoft Fluent Emoji (MIT), siehe CREDITS.md. Sind auf dem Server gemalte
 * Hintergründe importiert (artisan heldenhaus:import-backgrounds), zeigt die Szene stattdessen den des Tages.
 */
defineProps<{
    appearance: Appearance;
    companion: { egg_key: string; potion_key: string; is_mount: boolean; name: string } | null;
    hurt?: boolean;
    /** Gemalter Hintergrund des Tages, sonst die Fluent-Landschaft */
    background?: string | null;
}>();

const hour = new Date().getHours();
const phase = computed(() => (hour < 6 || hour >= 21 ? 'night' : hour < 9 ? 'morning' : hour >= 18 ? 'evening' : 'day'));
const night = computed(() => phase.value === 'night');
const sky = computed(
    () =>
        ({
            night: 'linear-gradient(180deg, #0b1026 0%, #1e1b4b 55%, #3b2f6b 100%)',
            morning: 'linear-gradient(180deg, #fbc2c8 0%, #fde2b6 55%, #fef3c7 100%)',
            day: 'linear-gradient(180deg, #4fb3f6 0%, #8fd3fe 55%, #d6f0ff 100%)',
            evening: 'linear-gradient(180deg, #5b3a9e 0%, #c2549a 50%, #fb9a5b 100%)',
        })[phase.value],
);
const hills = computed(() =>
    night.value
        ? ['#1f4a3a', '#173d30', '#10302a']
        : phase.value === 'evening'
          ? ['#6aa86b', '#4f9a5b', '#3d8a4c']
          : ['#9be27f', '#6fd16a', '#4cbf5a'],
);
const img = (name: string) => `/images/scene/${name}.webp`;
const stars = [
    [6, 12],
    [18, 30],
    [31, 8],
    [44, 22],
    [58, 10],
    [70, 28],
    [83, 14],
];
</script>

<template>
    <div class="scene relative min-h-48 overflow-hidden rounded-2xl border sm:min-h-52" :style="{ background: sky }">
        <div v-if="background" class="pointer-events-none absolute inset-0 select-none" aria-hidden="true">
            <img :src="background" alt="" class="absolute inset-0 h-full w-full object-cover" />
            <!-- Tageszeit als Tönung über dem Bild -->
            <div
                class="absolute inset-0"
                :class="{
                    'bg-indigo-950/55 mix-blend-multiply': night,
                    'bg-orange-400/25 mix-blend-multiply': phase === 'evening',
                    'bg-rose-200/20 mix-blend-soft-light': phase === 'morning',
                }"
            />
            <div class="absolute inset-x-0 bottom-0 h-1/3 bg-gradient-to-t from-black/30 to-transparent" />
        </div>
        <div v-else class="pointer-events-none absolute inset-0 select-none" aria-hidden="true">
            <!-- Sonne / Mond / Sterne -->
            <template v-if="night">
                <img
                    v-for="([x, y], i) in stars"
                    :key="i"
                    :src="img('star')"
                    alt=""
                    class="twinkle absolute w-4 sm:w-5"
                    :style="{ left: `${x}%`, top: `${y}%`, animationDelay: `${i * 0.4}s` }"
                />
                <img :src="img('moon')" alt="" class="absolute right-[8%] top-3 w-14 sm:w-16" />
            </template>
            <img
                v-else
                :src="img('sun')"
                alt=""
                class="absolute w-16 sm:w-20"
                :class="phase === 'morning' ? 'left-[38%] top-[22%]' : phase === 'evening' ? 'right-[30%] top-[28%]' : 'right-[8%] top-3'"
            />

            <!-- Wolken -->
            <div v-if="!night" class="clouds absolute inset-x-0 top-0 h-1/2">
                <img :src="img('cloud')" alt="" class="absolute left-[16%] top-[12%] w-20 opacity-95 sm:w-24" />
                <img :src="img('cloud')" alt="" class="absolute left-[56%] top-[34%] w-14 opacity-90" />
            </div>

            <!-- Berge am Horizont -->
            <div
                class="absolute inset-x-0 bottom-[16%] h-32"
                :class="night ? 'brightness-50 saturate-50' : phase === 'evening' ? 'brightness-90' : ''"
            >
                <img :src="img('mountain')" alt="" class="absolute bottom-0 left-[20%] w-32 sm:w-40" />
                <img :src="img('mountain')" alt="" class="absolute bottom-0 left-[36%] w-24 sm:w-28" />
                <img :src="img('castle')" alt="" class="absolute bottom-[24%] left-[60%] w-12 sm:w-14" />
            </div>

            <!-- Hügel -->
            <svg class="absolute inset-x-0 bottom-0 h-[42%] w-full" viewBox="0 0 600 100" preserveAspectRatio="none">
                <path d="M0 38 Q90 6 200 30 T420 22 T600 30 V100 H0 Z" :fill="hills[0]" />
                <path d="M0 62 Q140 34 300 58 T600 50 V100 H0 Z" :fill="hills[1]" />
                <path d="M0 84 Q180 66 360 82 T600 78 V100 H0 Z" :fill="hills[2]" />
            </svg>

            <!-- Das Heldenhaus, Bäume, Blumen -->
            <div class="absolute inset-0" :class="night ? 'saturate-75 brightness-[.6]' : ''">
                <img :src="img('fir')" alt="" class="absolute bottom-[26%] left-[34%] hidden w-12 sm:block" />
                <img :src="img('tree')" alt="" class="absolute bottom-[22%] right-[24%] w-16 sm:w-20" />
                <img :src="img('house')" alt="" class="absolute bottom-[14%] right-[3%] w-24 sm:w-28" />
                <img :src="img('fir')" alt="" class="absolute bottom-[30%] right-[1%] hidden w-10 md:block" />
                <img :src="img('tulip')" alt="" class="absolute bottom-[4%] left-[44%] w-7" />
                <img :src="img('mushroom')" alt="" class="absolute bottom-[3%] right-[30%] w-8" />
                <img :src="img('tulip')" alt="" class="absolute bottom-[8%] right-[36%] hidden w-6 sm:block" />
            </div>
        </div>

        <div class="relative flex min-h-48 items-end gap-1 px-2 pt-4 sm:min-h-52 sm:px-6">
            <HeroAvatar
                :avatar="appearance.avatar"
                :seed="appearance.seed"
                :equipment="appearance.equipment"
                :class-key="appearance.class_key"
                :mood="hurt ? 'hurt' : 'happy'"
                :backdrop="false"
                :size="156"
                class="-mb-1 drop-shadow-lg"
            />
            <PetArt
                v-if="companion"
                :egg="companion.egg_key"
                :potion="companion.potion_key"
                :mount="companion.is_mount"
                :size="64"
                class="mb-3 shrink-0"
            />
            <div class="mb-4 ml-auto max-w-[55%] text-right sm:mr-36">
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
        opacity: 0.35;
        transform: scale(0.8);
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
