<script setup lang="ts">
import Emoji from '@/components/game/Emoji.vue';

export interface Recap {
    since: string;
    quests: { name: string; icon: string; boss: boolean }[];
    active_quest: { name: string; icon: string; percent: number } | null;
    builds: { icon: string; text: string }[];
    event_tiers: { icon: string; text: string }[];
    family_tasks: number;
}

defineProps<{ recap: Recap }>();
</script>

<template>
    <!-- Familien-Wochenleistung als Bild, ohne Ranglisten -->
    <div
        class="relative overflow-hidden rounded-md border-2 border-[var(--wood-edge)] bg-gradient-to-b from-sky-200 via-sky-100 to-emerald-200 p-4 text-[#1f3510] dark:from-slate-800 dark:via-slate-800 dark:to-emerald-950 dark:text-emerald-50"
    >
        <div class="absolute inset-x-0 bottom-0 h-6 bg-[#8bcf5a] dark:bg-[#2f5d3a]" aria-hidden="true" />
        <div class="relative grid gap-4 pb-6 sm:grid-cols-2">
            <div class="flex flex-col items-center justify-center gap-1 text-center">
                <span class="text-6xl"><Emoji glyph="🏰" /></span>
                <p class="font-pixel text-3xl">{{ recap.family_tasks }}</p>
                <p class="text-sm font-bold">Familienaufgaben gemeinsam geschafft</p>
            </div>
            <div class="grid content-center gap-2">
                <p
                    v-for="(q, i) in recap.quests"
                    :key="`q${i}`"
                    class="flex items-center gap-2 rounded-md bg-white/70 px-2 py-1 text-sm font-bold dark:bg-black/30"
                >
                    <span class="text-2xl"><Emoji :glyph="q.icon" /></span> {{ q.boss ? `${q.name} besiegt!` : `${q.name} geschafft!` }}
                </p>
                <p
                    v-for="(b, i) in recap.builds"
                    :key="`b${i}`"
                    class="flex items-center gap-2 rounded-md bg-white/70 px-2 py-1 text-sm font-bold dark:bg-black/30"
                >
                    <span class="text-2xl"><Emoji :glyph="b.icon" /></span> {{ b.text }}
                </p>
                <p
                    v-for="(e, i) in recap.event_tiers"
                    :key="`e${i}`"
                    class="flex items-center gap-2 rounded-md bg-white/70 px-2 py-1 text-sm font-bold dark:bg-black/30"
                >
                    <span class="text-2xl"><Emoji :glyph="e.icon" /></span> {{ e.text }}
                </p>
                <div v-if="recap.active_quest" class="rounded-md bg-white/70 px-2 py-1 text-sm font-bold dark:bg-black/30">
                    <p class="flex items-center gap-2">
                        <Emoji :glyph="recap.active_quest.icon" /> {{ recap.active_quest.name }}: {{ recap.active_quest.percent }} %
                    </p>
                    <span class="mt-1 block h-2 overflow-hidden rounded-full bg-black/10">
                        <span class="block h-full rounded-full bg-rose-500" :style="{ width: `${recap.active_quest.percent}%` }" />
                    </span>
                </div>
                <p
                    v-if="!recap.quests.length && !recap.builds.length && !recap.event_tiers.length && !recap.active_quest"
                    class="rounded-md bg-white/70 px-2 py-1 text-sm font-bold dark:bg-black/30"
                >
                    Eine ruhige Woche – auch das ist gut. Was nehmt ihr euch vor?
                </p>
            </div>
        </div>
    </div>
</template>
