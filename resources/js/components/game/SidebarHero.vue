<script setup lang="ts">
import HeroAvatar from '@/components/game/HeroAvatar.vue';
import type { SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { Coins, Heart, Moon, Shield, Star } from 'lucide-vue-next';
import { computed } from 'vue';

const classNames: Record<string, string> = {
    warrior: 'Krieger',
    mage: 'Magier',
    rogue: 'Schurke',
    healer: 'Heiler',
    tinkerer: 'Tüftler',
    bard: 'Barde',
    ranger: 'Waldläufer',
    paladin: 'Paladin',
    tamer: 'Tierbändiger',
    cook: 'Koch',
    monk: 'Mönch',
};

const page = usePage<SharedData>();
const user = computed(() => page.props.auth.user);
const stats = computed(() => page.props.auth.stats);
const appearance = computed(() => page.props.auth.appearance);
const className = computed(() => (stats.value?.class_key ? classNames[stats.value.class_key] : null));
const percent = (value: number, max: number) => `${Math.max(0, Math.min(100, (value / Math.max(1, max)) * 100))}%`;
</script>

<template>
    <Link
        :href="route('hero')"
        class="mx-2 block rounded-md border-2 border-[var(--wood-edge)] bg-black/20 p-2.5 shadow-[inset_0_0_0_1px_rgb(255_220_160/0.12)] transition hover:bg-black/30"
    >
        <div class="flex items-center gap-2.5">
            <div class="border-[var(--gold)]/70 rounded-md border-2 bg-[#8fb3c9] p-0.5 shadow-[0_2px_0_rgb(0_0_0/0.4)]">
                <HeroAvatar
                    v-if="appearance"
                    :avatar="appearance.avatar"
                    :seed="appearance.seed"
                    :equipment="appearance.equipment"
                    :class-key="appearance.class_key"
                    :size="48"
                    portrait
                />
            </div>
            <div class="min-w-0 flex-1">
                <p class="truncate font-pixel text-lg leading-tight text-[var(--gold)]">{{ user.name }}</p>
                <p v-if="stats" class="truncate text-xs font-semibold text-sidebar-foreground/80">
                    Level {{ stats.level }}<template v-if="className"> · {{ className }}</template>
                    <Moon v-if="stats.sleeping" class="ml-1 inline size-3" />
                </p>
            </div>
        </div>
        <template v-if="stats">
            <div class="mt-2 space-y-1">
                <div class="flex items-center gap-1.5" :title="`Leben ${Math.floor(stats.hp)} / ${stats.max_hp}`">
                    <Heart class="size-3 shrink-0 fill-rose-500 text-rose-500" />
                    <div class="h-2 flex-1 overflow-hidden rounded-sm border border-black/60 bg-black/40">
                        <div class="h-full bg-gradient-to-r from-rose-600 to-red-400" :style="{ width: percent(stats.hp, stats.max_hp) }" />
                    </div>
                </div>
                <div class="flex items-center gap-1.5" :title="`Erfahrung ${Math.floor(stats.xp)} / ${stats.xp_to_next}`">
                    <span class="w-3 shrink-0 text-center font-pixel text-[9px] leading-none text-amber-300">XP</span>
                    <div class="h-2 flex-1 overflow-hidden rounded-sm border border-black/60 bg-black/40">
                        <div class="h-full bg-gradient-to-r from-amber-500 to-yellow-300" :style="{ width: percent(stats.xp, stats.xp_to_next) }" />
                    </div>
                </div>
                <div class="flex items-center gap-1.5" :title="`Mana ${Math.floor(stats.mp)} / ${stats.max_mp}`">
                    <span class="w-3 shrink-0 text-center font-pixel text-[9px] leading-none text-sky-300">MP</span>
                    <div class="h-2 flex-1 overflow-hidden rounded-sm border border-black/60 bg-black/40">
                        <div class="h-full bg-gradient-to-r from-sky-500 to-indigo-400" :style="{ width: percent(stats.mp, stats.max_mp) }" />
                    </div>
                </div>
            </div>
            <div class="mt-2 flex items-center gap-3 text-xs font-bold">
                <span class="flex items-center gap-1 text-amber-300" title="Gold – für die Spielwelt"
                    ><Coins class="size-3.5" />{{ stats.gold.toFixed(0) }}</span
                >
                <span class="flex items-center gap-1 text-violet-300" title="Sterne – für echte Belohnungen"
                    ><Star class="size-3.5 fill-current" />{{ stats.stars }}</span
                >
                <span v-if="stats.streak_shields" class="flex items-center gap-1 text-sky-300" title="Streak-Schutz"
                    ><Shield class="size-3.5" />{{ stats.streak_shields }}</span
                >
            </div>
        </template>
    </Link>
</template>
