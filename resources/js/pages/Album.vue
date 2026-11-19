<script setup lang="ts">
import Emoji from '@/components/game/Emoji.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';
import { BookOpen, Check, Coins, RotateCcw } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface RewardItem {
    key: string;
    name: string;
    icon: string;
    type: string;
}
interface Entry {
    key: string;
    name: string;
    icon: string;
    collected: boolean;
    event: { key: string; name: string } | null;
}
interface Album {
    key: string;
    name: string;
    icon: string;
    description: string;
    total: number;
    collected: number;
    percent: number;
    event_open: number;
    steps: { step: number; reached: boolean; gold: number; item: RewardItem | null }[];
    entries: Entry[];
}

const props = defineProps<{ albums: Album[] }>();

const active = ref(props.albums[0]?.key ?? '');
const album = computed(() => props.albums.find((a) => a.key === active.value) ?? props.albums[0]);
const onlyMissing = ref(false);
const entries = computed(() => (album.value?.entries ?? []).filter((e) => !onlyMissing.value || !e.collected));
</script>

<template>
    <Head title="Sammelalbum" />

    <AppLayout :breadcrumbs="[{ title: 'Sammelalbum', href: '/album' }]">
        <div class="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
            <div>
                <h1 class="flex items-center gap-2 text-2xl font-extrabold tracking-tight"><BookOpen class="size-6 text-amber-500" /> Sammelalbum</h1>
                <p class="text-sm text-muted-foreground">
                    Alles, was du einmal gesammelt hast, bleibt hier. Bei 25, 50, 75 und 100 % gibt es eine Belohnung.
                </p>
            </div>

            <nav class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3" aria-label="Album wählen">
                <button
                    v-for="a in albums"
                    :key="a.key"
                    class="hh-panel flex items-center gap-3 p-3 text-left transition"
                    :class="a.key === active ? 'ring-2 ring-amber-400' : 'hover:bg-muted/40'"
                    :aria-pressed="a.key === active"
                    @click="active = a.key"
                >
                    <span class="text-3xl"><Emoji :glyph="a.icon" /></span>
                    <div class="min-w-0 flex-1">
                        <p class="font-extrabold leading-tight">{{ a.name }}</p>
                        <div
                            class="mt-1 h-2.5 overflow-hidden rounded-full bg-muted"
                            role="progressbar"
                            :aria-valuenow="a.percent"
                            aria-valuemax="100"
                        >
                            <div class="h-full rounded-full bg-gradient-to-r from-amber-400 to-orange-500" :style="{ width: `${a.percent}%` }" />
                        </div>
                        <p class="mt-0.5 text-xs font-semibold text-muted-foreground">{{ a.collected }} / {{ a.total }} · {{ a.percent }} %</p>
                    </div>
                </button>
            </nav>

            <section v-if="album" class="hh-panel hh-parchment flex flex-col gap-4 p-4">
                <header class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="hh-panel-title flex items-center gap-2"><Emoji :glyph="album.icon" /> {{ album.name }}</h2>
                        <p class="text-sm text-muted-foreground">{{ album.description }}</p>
                        <p v-if="album.event_open" class="mt-1 flex items-center gap-1 text-xs font-semibold text-violet-700 dark:text-violet-300">
                            <RotateCcw class="size-3.5" /> {{ album.event_open }} Einträge gibt es nur bei Events – sie kommen wieder.
                        </p>
                    </div>
                    <label class="flex items-center gap-2 text-sm font-semibold">
                        <input v-model="onlyMissing" type="checkbox" class="size-4 accent-amber-500" /> Nur fehlende
                    </label>
                </header>

                <ol class="grid gap-2 sm:grid-cols-4" aria-label="Belohnungen">
                    <li
                        v-for="s in album.steps"
                        :key="s.step"
                        class="flex items-center gap-2 rounded-xl border p-2 text-sm"
                        :class="s.reached ? 'border-amber-400 bg-amber-50 dark:bg-amber-950/40' : 'bg-card'"
                    >
                        <span class="font-extrabold tabular-nums">{{ s.step }} %</span>
                        <span class="flex flex-1 flex-wrap items-center gap-1 text-xs">
                            <span class="inline-flex items-center gap-0.5 text-amber-600"><Coins class="size-3" />{{ s.gold }}</span>
                            <span v-if="s.item" class="inline-flex items-center gap-0.5"><Emoji :glyph="s.item.icon" /> {{ s.item.name }}</span>
                            <span>+ Abzeichen</span>
                        </span>
                        <Check v-if="s.reached" class="size-4 text-amber-600" aria-label="erhalten" />
                    </li>
                </ol>

                <ul class="grid grid-cols-2 gap-2 sm:grid-cols-4 lg:grid-cols-6">
                    <li
                        v-for="e in entries"
                        :key="e.key"
                        class="relative flex flex-col items-center gap-1 rounded-xl border bg-card p-2 text-center"
                        :class="e.collected ? '' : 'opacity-60'"
                        :title="e.event ? `Gibt es beim Event „${e.event.name}“ – es kommt wieder.` : undefined"
                    >
                        <span class="text-3xl" :class="e.collected ? '' : 'opacity-50 grayscale'"><Emoji :glyph="e.icon" /></span>
                        <span class="text-xs font-bold leading-tight">{{ e.name }}</span>
                        <span
                            v-if="e.event"
                            class="rounded-full bg-violet-100 px-1.5 text-[10px] font-semibold text-violet-700 dark:bg-violet-900/50 dark:text-violet-300"
                        >
                            {{ e.event.name }} · kommt wieder
                        </span>
                        <Check v-if="e.collected" class="absolute right-1 top-1 size-3.5 text-emerald-600" aria-label="gesammelt" />
                    </li>
                </ul>
                <p v-if="!entries.length" class="text-center text-sm font-semibold text-amber-700">Alles gesammelt! 🎉</p>
            </section>
        </div>
    </AppLayout>
</template>
