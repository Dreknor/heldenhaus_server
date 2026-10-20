<script setup lang="ts">
import AchievementBadge from '@/components/game/AchievementBadge.vue';
import ItemIcon from '@/components/game/ItemIcon.vue';
import type { SharedData } from '@/types';
import { Link, router, usePage } from '@inertiajs/vue3';
import { Coins, PartyPopper, TriangleAlert, X } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const page = usePage<SharedData>();
const message = ref<string | null>(null);
const error = ref<string | null>(null);
const levelUp = ref<number | null>(null);
const drop = ref<{ name: string; icon: string; rarity: string; key: string; type: string } | null>(null);
let timer: ReturnType<typeof setTimeout> | undefined;

// Neue Erfolge: Feier nach dem Level-Up, danach gelten sie als gesehen
const achievements = computed(() => page.props.newAchievements ?? []);
const showAchievements = computed(() => !levelUp.value && achievements.value.length > 0);
function closeAchievements() {
    router.post(route('achievements.seen'), {}, { preserveScroll: true, preserveState: true });
}

watch(
    () => [page.props.flash?.success, page.props.errors?.game, page.props.flash?.levelUp, page.props.flash?.drop],
    ([success, gameError, level, found]) => {
        drop.value = (found as typeof drop.value) ?? null;
        message.value = (success as string) ?? null;
        error.value = (gameError as string) ?? null;
        if (level) levelUp.value = level as number;
        clearTimeout(timer);
        timer = setTimeout(() => {
            message.value = null;
            error.value = null;
            drop.value = null;
        }, 4500);
    },
    { immediate: true },
);
</script>

<template>
    <div class="pointer-events-none fixed inset-x-0 bottom-4 z-50 flex flex-col items-center gap-2 px-4">
        <Transition enter-from-class="translate-y-4 opacity-0" leave-to-class="opacity-0" enter-active-class="transition" leave-active-class="transition">
            <div v-if="message" class="pointer-events-auto flex items-center gap-2 rounded-xl bg-emerald-600 px-4 py-2.5 font-semibold text-white shadow-lg">
                {{ message }}
                <button aria-label="Schließen" @click="message = null"><X class="size-4" /></button>
            </div>
        </Transition>
        <Transition enter-from-class="translate-y-4 scale-90 opacity-0" leave-to-class="opacity-0" enter-active-class="transition duration-300" leave-active-class="transition">
            <div v-if="drop" class="pointer-events-auto flex items-center gap-3 rounded-2xl bg-gradient-to-r from-violet-600 to-fuchsia-500 px-5 py-3 font-bold text-white shadow-xl">
                <span class="rounded-xl bg-white/90 p-1"><ItemIcon :item="drop" :size="40" /></span>
                <span>Fund! Du hast <span class="underline decoration-2">{{ drop.name }}</span> gefunden.</span>
            </div>
        </Transition>
        <Transition enter-from-class="translate-y-4 opacity-0" leave-to-class="opacity-0" enter-active-class="transition" leave-active-class="transition">
            <div v-if="error" class="pointer-events-auto flex items-center gap-2 rounded-xl bg-rose-600 px-4 py-2.5 font-semibold text-white shadow-lg">
                <TriangleAlert class="size-4" /> {{ error }}
            </div>
        </Transition>
    </div>

    <!-- Level-up -->
    <Transition enter-from-class="scale-75 opacity-0" leave-to-class="opacity-0" enter-active-class="transition duration-300" leave-active-class="transition">
        <div v-if="levelUp" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-6" @click="levelUp = null">
            <div class="max-w-sm rounded-3xl bg-gradient-to-br from-amber-300 via-orange-400 to-rose-500 p-1 text-center shadow-2xl">
                <div class="rounded-[1.3rem] bg-white px-8 py-8 dark:bg-slate-900">
                    <PartyPopper class="mx-auto mb-3 size-14 text-orange-500" />
                    <p class="text-sm font-bold uppercase tracking-widest text-muted-foreground">Level-Up!</p>
                    <p class="my-2 text-6xl font-extrabold text-orange-500">{{ levelUp }}</p>
                    <p class="font-semibold">Dein Held ist stärker geworden. Du hast einen neuen Attributpunkt!</p>
                    <button class="mt-5 rounded-xl bg-orange-500 px-6 py-2 font-bold text-white hover:bg-orange-600">Weiter</button>
                </div>
            </div>
        </div>
    </Transition>

    <!-- Neue Erfolge -->
    <Transition enter-from-class="scale-75 opacity-0" leave-to-class="opacity-0" enter-active-class="transition duration-300" leave-active-class="transition">
        <div v-if="showAchievements" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-6" role="dialog" aria-modal="true" aria-labelledby="achievement-title" @click.self="closeAchievements">
            <div class="w-full max-w-sm rounded-3xl bg-gradient-to-br from-amber-200 via-yellow-400 to-amber-600 p-1 text-center shadow-2xl">
                <div class="rounded-[1.3rem] bg-white px-6 py-7 dark:bg-slate-900">
                    <p id="achievement-title" class="text-sm font-bold uppercase tracking-widest text-muted-foreground">
                        {{ achievements.length > 1 ? `${achievements.length} neue Erfolge!` : 'Neuer Erfolg!' }}
                    </p>
                    <ul class="mt-4 grid max-h-[50vh] gap-4 overflow-y-auto">
                        <li v-for="a in achievements" :key="a.id" class="flex flex-col items-center gap-1" :class="{ 'flex-row text-left': achievements.length > 2 }">
                            <AchievementBadge :motif="a.motif" :tier="a.tier" :tiers="a.tiers" :size="achievements.length > 2 ? 52 : 96" class="animate-[bounce_1s_ease-in-out_2]" />
                            <div>
                                <p class="text-lg font-extrabold">{{ a.title }}</p>
                                <p class="text-sm text-muted-foreground">{{ a.description }}</p>
                                <p v-if="a.gold" class="inline-flex items-center gap-1 text-sm font-bold text-amber-600"><Coins class="size-4" /> +{{ a.gold }} Gold</p>
                            </div>
                        </li>
                    </ul>
                    <div class="mt-5 flex justify-center gap-2">
                        <Link :href="route('achievements.index')" class="rounded-xl border px-4 py-2 font-semibold hover:bg-muted" @click="closeAchievements">Alle Erfolge</Link>
                        <button class="rounded-xl bg-amber-500 px-6 py-2 font-bold text-white hover:bg-amber-600" @click="closeAchievements">Super!</button>
                    </div>
                </div>
            </div>
        </div>
    </Transition>
</template>
