<script setup lang="ts">
import HeroAvatar from '@/components/game/HeroAvatar.vue';
import PetArt from '@/components/game/PetArt.vue';
import PixelYard from '@/components/game/PixelYard.vue';
import type { Appearance } from '@/types';
import { computed } from 'vue';

/**
 * Szene im Heldenpanel (Konzept §2.9, G2): Himmel nach Tageszeit, Heldenhaus, Avatar und Begleiter.
 * Landschaft: Pixel-Hof (PixelYard, CraftPix), siehe CREDITS.md. Sind auf dem Server gemalte
 * Hintergründe importiert (artisan heldenhaus:import-backgrounds), zeigt die Szene stattdessen den des Tages.
 */
defineProps<{
    appearance: Appearance;
    companion: { egg_key: string; potion_key: string; is_mount: boolean; name: string } | null;
    hurt?: boolean;
    /** Gemalter Hintergrund des Tages, sonst die Fluent-Landschaft */
    background?: string | null;
    weather?: { type: 'clear' | 'cloudy' | 'fog' | 'rain' | 'snow' | 'storm'; label: string; is_day: boolean } | null;
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
const img = (name: string) => `/images/scene/${name}.webp`;
const rainDrops = Array.from({ length: 32 }, (_, i) => ({
    left: `${(i * 37) % 100}%`,
    delay: `${(i % 8) * -0.31}s`,
    duration: `${0.75 + (i % 4) * 0.12}s`,
}));
const snowflakes = Array.from({ length: 26 }, (_, i) => ({
    left: `${(i * 43) % 100}%`,
    delay: `${(i % 9) * -0.55}s`,
    duration: `${3 + (i % 4)}s`,
    size: `${4 + (i % 3) * 2}px`,
}));
</script>

<template>
    <div class="scene relative min-h-56 overflow-hidden rounded-2xl border sm:min-h-72" :style="{ background: sky }">
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
        <PixelYard v-else :phase="phase" />

        <div
            v-if="weather"
            class="pointer-events-none absolute inset-0 z-[1] overflow-hidden"
            :class="{
                'bg-slate-950/20': weather.type === 'rain' || weather.type === 'storm',
                'bg-sky-100/15': weather.type === 'snow' || weather.type === 'fog',
            }"
            aria-hidden="true"
        >
            <div
                v-if="weather.type === 'clear' && weather.is_day"
                class="weather-sun absolute right-[12%] top-5 size-16 rounded-full bg-amber-200/70 blur-[2px] sm:size-20"
            />
            <img
                v-if="weather.type === 'cloudy' || weather.type === 'fog'"
                :src="img('cloud')"
                alt=""
                class="absolute right-[18%] top-5 w-24 opacity-80 sm:w-28"
            />
            <div v-if="weather.type === 'rain' || weather.type === 'storm'" class="absolute inset-0">
                <span
                    v-for="(drop, i) in rainDrops"
                    :key="i"
                    class="rain-drop absolute top-[-12%] h-7 w-px bg-sky-100/75"
                    :style="{ left: drop.left, animationDelay: drop.delay, animationDuration: drop.duration }"
                />
            </div>
            <div v-if="weather.type === 'snow'" class="absolute inset-0">
                <span
                    v-for="(flake, i) in snowflakes"
                    :key="i"
                    class="snowflake absolute top-[-8%] rounded-full bg-white/90 shadow-sm"
                    :style="{
                        left: flake.left,
                        width: flake.size,
                        height: flake.size,
                        animationDelay: flake.delay,
                        animationDuration: flake.duration,
                    }"
                />
            </div>
        </div>

        <div
            v-if="weather"
            class="pointer-events-none absolute left-3 top-3 z-10 rounded-full bg-slate-950/45 px-2.5 py-1 text-xs font-semibold text-white shadow-sm backdrop-blur-sm"
        >
            <span aria-hidden="true">{{
                { clear: weather.is_day ? '☀️' : '🌙', cloudy: '☁️', fog: '🌫️', rain: '🌧️', snow: '❄️', storm: '⛈️' }[weather.type]
            }}</span>
            {{ weather.label }}
        </div>

        <div class="relative z-[2] flex min-h-56 items-end gap-1 px-2 pt-4 sm:min-h-72 sm:px-6">
            <!-- Reittier: der Held sitzt obendrauf (Beine verdeckt), sonst steht er mit Haustier daneben -->
            <div v-if="companion?.is_mount" class="riding relative -mb-1 shrink-0" :aria-label="`${companion.name} als Reittier`">
                <PetArt :egg="companion.egg_key" :potion="companion.potion_key" :size="150" class="relative z-0" />
                <HeroAvatar
                    :avatar="appearance.avatar"
                    :seed="appearance.seed"
                    :equipment="appearance.equipment"
                    :class-key="appearance.class_key"
                    :mood="hurt ? 'hurt' : 'happy'"
                    :backdrop="false"
                    :size="132"
                    class="rider z-10 drop-shadow-lg"
                />
                <!-- Sattel verdeckt den Übergang zwischen Reiter und Tier -->
                <div class="saddle z-20" aria-hidden="true">
                    <span class="saddle-blanket" />
                    <span class="saddle-seat" />
                    <span class="saddle-stirrup" />
                </div>
            </div>
            <template v-else>
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
                <PetArt v-if="companion" :egg="companion.egg_key" :potion="companion.potion_key" :size="64" class="mb-3 shrink-0" />
            </template>
            <div class="absolute right-3 top-3 max-w-[78%] text-right sm:static sm:mb-4 sm:ml-auto sm:mr-36 sm:max-w-[55%]">
                <slot />
            </div>
        </div>
    </div>
</template>

<style scoped>
/* Reiter: Unterkörper ausblenden, damit der Held im Sattel statt vor dem Tier steht */
.rider {
    position: absolute !important;
    left: 50%;
    transform: translateX(-50%);
    bottom: 34%;
    -webkit-mask-image: linear-gradient(to bottom, #000 62%, transparent 71%);
    mask-image: linear-gradient(to bottom, #000 62%, transparent 71%);
    animation: ride 1.6s ease-in-out infinite;
}
.saddle {
    position: absolute;
    left: 50%;
    bottom: calc(34% + 30px);
    width: 62px;
    height: 26px;
    transform: translateX(-50%);
    animation: ride 1.6s ease-in-out infinite;
    pointer-events: none;
}
.saddle-blanket {
    position: absolute;
    inset: 8px 0 0;
    border-radius: 3px 3px 10px 10px;
    background: repeating-linear-gradient(90deg, #b91c1c 0 6px, #991b1b 6px 12px);
    border: 2px solid #3b1d0e;
    box-shadow: inset 0 -4px 0 #facc15;
}
.saddle-seat {
    position: absolute;
    left: 9px;
    right: 9px;
    top: 0;
    height: 14px;
    border-radius: 10px 10px 4px 4px;
    background: linear-gradient(#a0522d, #6b3410);
    border: 2px solid #3b1d0e;
    box-shadow: inset 0 3px 0 rgb(255 255 255 / 25%);
}
.saddle-stirrup {
    position: absolute;
    left: 50%;
    top: 22px;
    width: 10px;
    height: 16px;
    transform: translateX(-50%);
    border: 2px solid #3b1d0e;
    border-top: 6px solid #6b3410;
    border-radius: 0 0 5px 5px;
    background: transparent;
}
.riding :deep(.pet) {
    animation: ride 1.6s ease-in-out infinite;
}
@keyframes ride {
    50% {
        transform: translate(-50%, -3px);
    }
}
.riding :deep(.pet) {
    animation-name: ride-pet;
}
@keyframes ride-pet {
    50% {
        transform: translateY(-3px);
    }
}
.twinkle {
    animation: twinkle 2.4s ease-in-out infinite;
}
.clouds {
    animation: drift 30s linear infinite alternate;
}
.weather-sun {
    box-shadow: 0 0 34px 14px rgb(253 224 71 / 35%);
}
.rain-drop {
    animation: rain linear infinite;
}
.snowflake {
    animation: snow linear infinite;
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
@keyframes rain {
    to {
        transform: translate3d(-18px, 135%, 0);
    }
}
@keyframes snow {
    to {
        transform: translate3d(24px, 135%, 0);
    }
}
@media (prefers-reduced-motion: reduce) {
    .rider,
    .saddle,
    .twinkle,
    .clouds,
    .rain-drop,
    .snowflake {
        animation: none;
    }
}
</style>
