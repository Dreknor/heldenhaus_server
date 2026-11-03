<script setup lang="ts">
import { computed, ref } from 'vue';

/**
 * Pixel-Hof hinter dem Helden (Konzept §2.9, G2): Wiese mit Heldenhaus, wogenden Bäumen, Brunnen,
 * einer Katze, die aus dem Gras springt (anklickbar), und hüpfenden Vögeln. Gleicher Top-down-Pixelstil
 * wie die LPC-Avatare. Grafiken: CraftPix „Characters Home“ (Freebie-Lizenz), siehe CREDITS.md.
 */
const props = defineProps<{ phase: 'night' | 'morning' | 'day' | 'evening' }>();

const night = computed(() => props.phase === 'night');
const src = (name: string) => `/images/hof/${name}.png`;

// Animierte Bäume: Spritesheet mit 9 Spalten (3 Baumarten × 3 Größen) und 13 Bildern à 64×80
const backTrees = [
    { col: 2, left: '1%', bottom: '52%', delay: '-0.2s' },
    { col: 8, left: '11%', bottom: '55%', delay: '-0.9s' },
    { col: 5, left: '47%', bottom: '55%', delay: '-1.1s' },
    { col: 8, left: '90%', bottom: '58%', delay: '-0.7s' },
];
const stars = [
    [6, 10],
    [15, 26],
    [27, 8],
    [38, 20],
    [52, 6],
    [63, 24],
    [74, 12],
    [86, 30],
    [94, 9],
];
const flowers = [
    { name: 'flowers-1', left: '30%', bottom: '6%' },
    { name: 'flowers-3', left: '47%', bottom: '3%' },
    { name: 'flowers-2', left: '58%', bottom: '10%' },
    { name: 'flower-tall', left: '22%', bottom: '2%' },
    { name: 'grass-1', left: '38%', bottom: '14%' },
    { name: 'grass-2', left: '66%', bottom: '4%' },
    { name: 'flowers-4', left: '93%', bottom: '3%' },
    { name: 'grass-1', left: '12%', bottom: '18%' },
];

// Die Katze springt beim Laden einmal aus dem Gras – und wieder, wenn man sie antippt
const catRun = ref(0);
function poke() {
    catRun.value++;
}
</script>

<template>
    <div class="yard pointer-events-none absolute inset-0 select-none" aria-hidden="true">
        <!-- Himmel: Sonne / Mond und Sterne -->
        <template v-if="night">
            <span
                v-for="([x, y], i) in stars"
                :key="i"
                class="twinkle absolute size-1 bg-white sm:size-1.5"
                :style="{ left: `${x}%`, top: `${y}%`, animationDelay: `${i * 0.37}s` }"
            />
            <span class="moon absolute right-[9%] top-4 size-10 rounded-full bg-amber-50 sm:size-12" />
        </template>
        <span
            v-else
            class="sun absolute size-12 rounded-full bg-yellow-200 sm:size-14"
            :class="phase === 'morning' ? 'left-[34%] top-[26%]' : phase === 'evening' ? 'right-[34%] top-[30%] bg-orange-200' : 'right-[9%] top-4'"
        />
        <div v-if="!night" class="clouds absolute inset-x-0 top-2 h-1/3">
            <span class="cloud absolute left-[14%] top-[18%]" />
            <span class="cloud cloud-sm absolute left-[60%] top-[44%]" />
        </div>

        <!-- Wiese -->
        <div class="meadow absolute inset-x-0 bottom-0 h-[58%]" :class="night ? 'meadow-night' : phase === 'evening' ? 'meadow-evening' : ''" />

        <div class="absolute inset-0" :class="night ? 'brightness-[.55] saturate-[.7]' : phase === 'evening' ? 'sepia-[.15]' : ''">
            <!-- hintere Baumreihe -->
            <span
                v-for="(t, i) in backTrees"
                :key="i"
                class="tree px absolute"
                :style="{ left: t.left, bottom: t.bottom, '--col': t.col, animationDelay: t.delay, filter: 'brightness(.88)' }"
            />

            <!-- großer Apfelbaum und Eiche -->
            <span class="tree tree-lg px absolute bottom-[30%] left-[24%] hidden sm:block" style="--col: 3; animation-delay: -0.4s" />
            <span class="tree tree-lg px absolute bottom-[34%] right-[31%]" style="--col: 0; animation-delay: -1s" />

            <!-- Heldenhaus mit Rauch und Fensterlicht -->
            <div class="house absolute bottom-[14%] right-[2%]">
                <img :src="src('house')" alt="" class="px block size-full" />
                <span v-for="i in 3" :key="i" class="smoke absolute rounded-full bg-slate-100/80" :style="{ animationDelay: `${(i - 1) * 1.1}s` }" />
                <template v-if="night || phase === 'evening'">
                    <span
                        v-for="(w, i) in [
                            [27, 57],
                            [43, 57],
                            [12, 81],
                            [30, 81],
                            [50, 81],
                        ]"
                        :key="i"
                        class="window absolute"
                        :style="{ left: `${w[0]}%`, top: `${w[1]}%` }"
                    />
                </template>
            </div>
            <img :src="src('stones-path')" alt="" class="px prop absolute bottom-[4%] right-[17%]" style="--w: 34; --h: 34" />

            <!-- Requisiten -->
            <img :src="src('well')" alt="" class="px prop absolute bottom-[8%] right-[32%] hidden md:block" style="--w: 38; --h: 38" />
            <img :src="src('bush-big')" alt="" class="px prop absolute bottom-[20%] left-[36%]" style="--w: 36; --h: 37" />
            <img :src="src('mushroom-big')" alt="" class="px prop absolute bottom-[3%] left-[41%]" style="--w: 32; --h: 41" />
            <img :src="src('mushrooms')" alt="" class="px prop absolute bottom-[12%] left-[55%]" style="--w: 24; --h: 25" />
            <img :src="src('rock-blue')" alt="" class="px prop absolute bottom-[26%] left-[60%] hidden sm:block" style="--w: 29; --h: 30" />
            <img :src="src('stump')" alt="" class="px prop absolute bottom-[30%] left-[15%] hidden sm:block" style="--w: 37; --h: 28" />
            <img :src="src('crates')" alt="" class="px prop absolute bottom-[10%] right-[1%]" style="--w: 25; --h: 37" />
            <img
                v-for="(f, i) in flowers"
                :key="i"
                :src="src(f.name)"
                alt=""
                class="px prop prop-auto absolute"
                :style="{ left: f.left, bottom: f.bottom }"
            />

            <!-- Vögel und Katze -->
            <span class="bird px absolute bottom-[34%] left-[46%]" />
            <span class="bird px absolute bottom-[22%] left-[50%]" style="--row: 1; animation-delay: -0.6s" />
            <button
                :key="catRun"
                type="button"
                tabindex="-1"
                class="cat px pointer-events-auto absolute bottom-[6%] right-[25%]"
                aria-hidden="true"
                @click="poke"
            />
        </div>
    </div>
</template>

<style scoped>
.yard {
    --s: 1.5;
}
@media (min-width: 640px) {
    .yard {
        --s: 2;
    }
}
.px {
    image-rendering: pixelated;
}
.prop {
    width: calc(var(--w) * var(--s) * 1px);
    height: calc(var(--h) * var(--s) * 1px);
}
.prop-auto {
    width: auto;
    height: auto;
    zoom: var(--s);
}
.meadow {
    background:
        radial-gradient(ellipse 30% 18% at 20% 70%, rgb(255 255 255 / 0.08), transparent 70%),
        radial-gradient(ellipse 25% 14% at 75% 40%, rgb(0 0 0 / 0.05), transparent 70%),
        linear-gradient(180deg, #8bcf5a 0%, #74bf4c 40%, #5fae42 100%);
    border-top: 3px solid #a3dc6c;
}
.meadow-evening {
    background: linear-gradient(180deg, #7fb653 0%, #67a646 40%, #548f3b 100%);
}
.meadow-night {
    background: linear-gradient(180deg, #2f5d3a 0%, #254d31 50%, #1c3f28 100%);
    border-top-color: #3d6e47;
}
.tree {
    width: calc(64px * var(--s) * 0.75);
    height: calc(80px * var(--s) * 0.75);
    background-image: url('/images/hof/trees-anim.png');
    background-size: calc(576px * var(--s) * 0.75) calc(1040px * var(--s) * 0.75);
    background-position-x: calc(var(--col) * -64px * var(--s) * 0.75);
    animation: sway 2.6s steps(13) infinite;
    --scale: 0.75;
}
.tree-lg {
    width: calc(64px * var(--s) * 1.05);
    height: calc(80px * var(--s) * 1.05);
    background-size: calc(576px * var(--s) * 1.05) calc(1040px * var(--s) * 1.05);
    background-position-x: calc(var(--col) * -64px * var(--s) * 1.05);
    animation-name: sway-lg;
}
@keyframes sway {
    from {
        background-position-y: 0;
    }
    to {
        background-position-y: calc(-1040px * var(--s) * 0.75);
    }
}
@keyframes sway-lg {
    from {
        background-position-y: 0;
    }
    to {
        background-position-y: calc(-1040px * var(--s) * 1.05);
    }
}
.house {
    width: calc(131px * var(--s) * 0.82);
    height: calc(127px * var(--s) * 0.82);
}
.smoke {
    left: 26%;
    top: 4%;
    width: calc(7px * var(--s));
    height: calc(7px * var(--s));
    opacity: 0;
    animation: smoke 3.3s ease-out infinite;
}
@keyframes smoke {
    0% {
        transform: translate(0, 0) scale(0.6);
        opacity: 0;
    }
    15% {
        opacity: 0.85;
    }
    100% {
        transform: translate(14px, -38px) scale(1.8);
        opacity: 0;
    }
}
.window {
    width: 9%;
    height: 8%;
    background: #fde68a;
    box-shadow: 0 0 10px 4px rgb(253 224 71 / 0.55);
    mix-blend-mode: screen;
    opacity: 0.85;
}
.bird {
    width: calc(32px * var(--s) * 0.8);
    height: calc(32px * var(--s) * 0.8);
    background-image: url('/images/hof/bird-anim.png');
    background-size: calc(640px * var(--s) * 0.8) calc(96px * var(--s) * 0.8);
    background-position-y: calc(var(--row, 0) * -32px * var(--s) * 0.8);
    animation: hop 2.4s steps(20) infinite;
}
@keyframes hop {
    from {
        background-position-x: 0;
    }
    to {
        background-position-x: calc(-640px * var(--s) * 0.8);
    }
}
.cat {
    width: calc(32px * var(--s));
    height: calc(32px * var(--s));
    background: url('/images/hof/cat-anim.png') 0 0 / calc(96px * var(--s)) calc(576px * var(--s));
    image-rendering: pixelated;
    animation: cat-pop 1.6s steps(15) 0.8s both;
    cursor: pointer;
}
@keyframes cat-pop {
    from {
        background-position-y: 0;
    }
    to {
        background-position-y: calc(-15 * 32px * var(--s));
    }
}
.sun {
    box-shadow:
        0 0 0 6px rgb(254 240 138 / 0.35),
        0 0 30px 12px rgb(253 224 71 / 0.45);
}
.moon {
    box-shadow:
        inset -8px -4px 0 0 #e7dcc0,
        0 0 24px 6px rgb(254 243 199 / 0.35);
}
.cloud {
    width: 72px;
    height: 20px;
    background: #fff;
    border-radius: 999px;
    opacity: 0.92;
    box-shadow:
        14px -10px 0 2px #fff,
        36px -6px 0 4px #fff;
}
.cloud-sm {
    transform: scale(0.7);
}
.clouds {
    animation: drift 34s linear infinite alternate;
}
.twinkle {
    animation: twinkle 2.4s ease-in-out infinite;
}
@keyframes drift {
    to {
        transform: translateX(48px);
    }
}
@keyframes twinkle {
    50% {
        opacity: 0.3;
    }
}
@media (prefers-reduced-motion: reduce) {
    .tree,
    .bird,
    .smoke,
    .clouds,
    .twinkle {
        animation: none;
    }
    .cat {
        animation-duration: 0s;
    }
}
</style>
