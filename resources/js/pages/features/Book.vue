<script setup lang="ts">
import Emoji from '@/components/game/Emoji.vue';
import FeatureProgress from '@/components/game/FeatureProgress.vue';
import GamePanel from '@/components/game/GamePanel.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { FeatureEntry, FeatureRewards, FeatureTourStep } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { BookOpen, Check, Coins, Eye, Lock, Map, Sparkles } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface BookEntry extends FeatureEntry {
    text: string | null;
    tour: FeatureTourStep[];
    starter: { goal: string; rewards?: FeatureRewards } | null;
    starter_done: boolean;
    unlocked_at: string | null;
}

/** Heldenbuch (§13.3): alle Funktionen, was schon frei ist, was wann kommt, Erklärungen und Touren zum Wiederholen. */
const props = defineProps<{
    features: BookEntry[];
    level: number;
    enforced: boolean;
    showAll: boolean;
    canShowAll: boolean;
}>();

const open = computed(() => props.features.filter((f) => f.open));
const later = computed(() => props.features.filter((f) => !f.open));
const busy = ref(false);

const tourHref = (f: BookEntry) => `${f.href}${f.href.includes('?') ? '&' : '?'}tour=${f.key}`;

function toggleShowAll() {
    const on = !props.showAll;
    if (on && !confirm('Alle Funktionen sofort freischalten? Was einmal frei ist, bleibt frei.')) return;
    busy.value = true;
    router.post(route('features.show-all'), { on }, { preserveScroll: true, onFinish: () => (busy.value = false) });
}
</script>

<template>
    <Head title="Heldenbuch" />

    <AppLayout :breadcrumbs="[{ title: 'Heldenbuch', href: '/heldenbuch' }]">
        <div class="flex min-w-0 flex-1 flex-col gap-5 p-4 md:p-6">
            <div>
                <h1 class="flex items-center gap-2 text-2xl font-extrabold tracking-tight"><BookOpen class="size-6" /> Heldenbuch</h1>
                <p class="text-sm text-muted-foreground">
                    Hier steht alles, was du im Heldenhaus machen kannst. Neues kommt Schritt für Schritt mit deinem Level dazu.
                </p>
            </div>

            <div class="grid gap-5 xl:grid-cols-12">
                <div class="flex flex-col gap-5 xl:col-span-8">
                    <GamePanel title="Schon freigeschaltet" :subtitle="`${open.length} von ${features.length} Funktionen`">
                        <p class="rounded-md bg-background/50 p-3 text-sm">
                            <strong>Von Anfang an da:</strong> Aufgaben, Familienaufgaben, Sterne und Belohnungsshop, Taschengeld, Familien-Feed,
                            Erfolge, Familien-Boss und alle Alltagshilfen.
                        </p>
                        <article
                            v-for="f in open"
                            :key="f.key"
                            class="grid gap-2 rounded-md border-2 border-[var(--parchment-edge)] bg-background/50 p-3"
                            :data-feature="f.key"
                        >
                            <div class="flex flex-wrap items-center gap-3">
                                <span class="text-4xl"><Emoji :glyph="f.emoji" /></span>
                                <div class="min-w-0 flex-1">
                                    <h2 class="font-extrabold">{{ f.title }}</h2>
                                    <p class="text-xs text-muted-foreground">
                                        {{
                                            f.unlocked_at
                                                ? `Freigeschaltet am ${new Date(f.unlocked_at).toLocaleDateString('de-DE')}`
                                                : `Level ${f.level}`
                                        }}
                                    </p>
                                </div>
                                <Link
                                    v-if="f.tour.length"
                                    :href="tourHref(f)"
                                    class="hh-button border-[var(--wood-edge)] bg-transparent px-2 py-1 text-xs"
                                >
                                    <Map class="size-3.5" /> Tour
                                </Link>
                                <Link
                                    :href="f.href"
                                    class="hh-button border-[var(--wood-edge)] bg-[var(--gold)] px-2 py-1 text-xs text-[var(--wood-edge)] hover:brightness-110"
                                >
                                    Öffnen
                                </Link>
                            </div>
                            <p v-if="f.text" class="text-sm">{{ f.text }}</p>
                            <p v-if="f.starter" class="flex flex-wrap items-center gap-2 text-xs font-semibold">
                                <template v-if="f.starter_done">
                                    <Check class="size-3.5 text-emerald-600" />
                                    <span class="text-muted-foreground line-through">{{ f.starter.goal }}</span>
                                </template>
                                <template v-else>
                                    <span>Erste Mini-Aufgabe: {{ f.starter.goal }}</span>
                                    <span v-if="f.starter.rewards?.gold" class="flex items-center gap-0.5 text-amber-700 dark:text-amber-300"
                                        ><Coins class="size-3" /> {{ f.starter.rewards.gold }}</span
                                    >
                                    <span v-if="f.starter.rewards?.xp" class="flex items-center gap-0.5 text-amber-700 dark:text-amber-300"
                                        ><Sparkles class="size-3" /> {{ f.starter.rewards.xp }} XP</span
                                    >
                                </template>
                            </p>
                        </article>
                    </GamePanel>

                    <GamePanel v-if="later.length" title="Kommt noch" :icon="Lock" subtitle="Freu dich drauf – jede Aufgabe bringt dich näher.">
                        <div class="grid gap-2 sm:grid-cols-2">
                            <article
                                v-for="f in later"
                                :key="f.key"
                                class="flex items-center gap-3 rounded-md border-2 border-dashed border-[var(--parchment-edge)] p-3"
                                :data-feature="f.key"
                            >
                                <span class="text-3xl opacity-50 grayscale"><Emoji :glyph="f.emoji" /></span>
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-extrabold">
                                        <template v-if="f.state === 'deferred'">{{ f.title }} – kommt etwas später</template>
                                        <template v-else>Ab Level {{ f.level }}: {{ f.title }}</template>
                                    </p>
                                    <p v-if="f.teaser" class="text-xs text-muted-foreground">{{ f.teaser }}</p>
                                </div>
                            </article>
                        </div>
                    </GamePanel>
                </div>

                <div class="flex flex-col gap-5 xl:col-span-4">
                    <FeatureProgress :show-book-link="false" />

                    <GamePanel v-if="enforced && canShowAll" title="Alles sofort zeigen" :icon="Eye">
                        <p class="text-sm">
                            {{
                                showAll
                                    ? 'Du siehst alle Funktionen. Schaltest du das aus, kommen neue Funktionen wieder Schritt für Schritt. Was schon frei ist, bleibt frei.'
                                    : 'Du kennst dich aus? Dann schalte alle Funktionen auf einmal frei, statt auf das nächste Level zu warten.'
                            }}
                        </p>
                        <button
                            class="hh-button justify-self-start border-[var(--wood-edge)] px-3 py-1.5 text-sm"
                            :class="showAll ? 'bg-transparent' : 'bg-[var(--gold)] text-[var(--wood-edge)] hover:brightness-110'"
                            :disabled="busy"
                            :aria-pressed="showAll"
                            @click="toggleShowAll"
                        >
                            {{ showAll ? 'Wieder Schritt für Schritt' : 'Alles sofort zeigen' }}
                        </button>
                    </GamePanel>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
