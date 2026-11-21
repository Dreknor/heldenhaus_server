<script setup lang="ts">
import Emoji from '@/components/game/Emoji.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';
import { ref } from 'vue';

const props = defineProps<{ cards: { icon: string; title: string; text: string }[] }>();
const index = ref(0);
const next = () => (index.value = Math.min(props.cards.length - 1, index.value + 1));
const prev = () => (index.value = Math.max(0, index.value - 1));
</script>

<template>
    <Head title="Eltern-Anleitung" />

    <AppLayout :breadcrumbs="[{ title: 'Eltern-Anleitung', href: '/help/parents' }]">
        <div class="flex flex-1 flex-col items-center gap-5 p-4 md:p-6">
            <h1 class="self-start text-2xl font-extrabold tracking-tight">So gelingt Heldenhaus</h1>

            <article class="hh-panel hh-parchment flex w-full max-w-xl flex-col items-center gap-3 p-6 text-center" aria-live="polite">
                <span class="text-6xl"><Emoji :glyph="cards[index].icon" /></span>
                <p class="text-xs font-bold text-muted-foreground">Tipp {{ index + 1 }} von {{ cards.length }}</p>
                <h2 class="hh-panel-title">{{ cards[index].title }}</h2>
                <p class="text-base">{{ cards[index].text }}</p>
                <div class="flex items-center gap-3 pt-2">
                    <button
                        class="hh-button border-[var(--wood-edge)] bg-background"
                        :disabled="index === 0"
                        aria-label="Vorheriger Tipp"
                        @click="prev"
                    >
                        <ChevronLeft class="size-4" />
                    </button>
                    <span class="flex gap-1" aria-hidden="true">
                        <span
                            v-for="(c, i) in cards"
                            :key="i"
                            class="size-2 rounded-full"
                            :class="i === index ? 'bg-primary' : 'bg-muted-foreground/30'"
                        />
                    </span>
                    <button v-if="index < cards.length - 1" class="hh-button border-[#2c4a14] bg-primary text-primary-foreground" @click="next">
                        Weiter <ChevronRight class="size-4" />
                    </button>
                    <Link v-else :href="route('dashboard')" class="hh-button border-[var(--wood-edge)] bg-[var(--gold)] text-[var(--wood-edge)]"
                        >Los geht's</Link
                    >
                </div>
            </article>

            <details class="w-full max-w-xl text-sm">
                <summary class="cursor-pointer font-bold">Alle Tipps auf einen Blick</summary>
                <ol class="mt-2 grid gap-2">
                    <li v-for="(c, i) in cards" :key="i" class="rounded-md border bg-card p-3">
                        <p class="font-bold"><Emoji :glyph="c.icon" /> {{ i + 1 }}. {{ c.title }}</p>
                        <p class="text-muted-foreground">{{ c.text }}</p>
                    </li>
                </ol>
            </details>
        </div>
    </AppLayout>
</template>
