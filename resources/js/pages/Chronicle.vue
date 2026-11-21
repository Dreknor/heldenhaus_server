<script setup lang="ts">
import Emoji from '@/components/game/Emoji.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { BookMarked, Lock, Square, Volume2 } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

interface Chapter {
    key: string;
    number: number;
    title: string;
    image: string | null;
    unlock: { level: number; bosses: number };
    unlocked: boolean;
    read: boolean;
    together: boolean;
    text: string[];
}

const props = defineProps<{
    chapters: Chapter[];
    level: number;
    bosses: number;
    family: string;
    crest: string;
}>();

const firstOpen = props.chapters.find((c) => c.unlocked && !c.read) ?? [...props.chapters].reverse().find((c) => c.unlocked) ?? null;
const selected = ref<string | null>(firstOpen?.key ?? null);
const chapter = computed(() => props.chapters.find((c) => c.key === selected.value) ?? null);
const unlockedCount = computed(() => props.chapters.filter((c) => c.unlocked).length);

function requirement(c: Chapter): string {
    const parts = [`Level ${c.unlock.level}`];
    if (c.unlock.bosses) parts.push(`${c.unlock.bosses} ${c.unlock.bosses === 1 ? 'Familien-Boss' : 'Familien-Bosse'} besiegt`);
    return parts.join(' · ');
}

function open(c: Chapter) {
    if (!c.unlocked) return;
    stop();
    selected.value = c.key;
    if (!c.read) router.post(route('chronicle.read', { key: c.key }), {}, { preserveScroll: true, preserveState: true });
}

// Vorlesen über die Sprachausgabe des Geräts (Web Speech API, ohne Server)
const canSpeak = typeof window !== 'undefined' && 'speechSynthesis' in window;
const speaking = ref(false);

function germanVoice(): SpeechSynthesisVoice | null {
    const voices = window.speechSynthesis.getVoices();
    return voices.find((v) => v.lang === 'de-DE') ?? voices.find((v) => v.lang.startsWith('de')) ?? null;
}

function speak() {
    if (!canSpeak || !chapter.value) return;
    stop();
    const utterance = new SpeechSynthesisUtterance(`Kapitel ${chapter.value.number}. ${chapter.value.title}. ${chapter.value.text.join(' ')}`);
    utterance.lang = 'de-DE';
    utterance.rate = 0.95;
    const voice = germanVoice();
    if (voice) utterance.voice = voice;
    utterance.onend = () => (speaking.value = false);
    utterance.onerror = () => (speaking.value = false);
    speaking.value = true;
    window.speechSynthesis.speak(utterance);
}

function stop() {
    if (canSpeak) window.speechSynthesis.cancel();
    speaking.value = false;
}

// Das zuerst gezeigte Kapitel gilt als gelesen
onMounted(() => {
    if (chapter.value && !chapter.value.read)
        router.post(route('chronicle.read', { key: chapter.value.key }), {}, { preserveScroll: true, preserveState: true });
});
onBeforeUnmount(stop);
</script>

<template>
    <Head title="Chronik" />

    <AppLayout :breadcrumbs="[{ title: 'Chronik', href: '/chronicle' }]">
        <div class="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
            <div>
                <h1 class="flex items-center gap-2 text-2xl font-extrabold tracking-tight">
                    <BookMarked class="size-6 text-amber-700" /> Chronik des Heldenhauses
                </h1>
                <p class="text-sm text-muted-foreground">
                    Die Geschichte von {{ family }} <Emoji :glyph="crest" />. Neue Kapitel öffnen sich mit deinem Level und besiegten Familien-Bossen
                    – {{ unlockedCount }} von {{ chapters.length }} sind offen.
                </p>
            </div>

            <div class="grid gap-6 lg:grid-cols-[18rem_1fr]">
                <nav class="hh-panel flex flex-col gap-1 p-2" aria-label="Kapitel">
                    <button
                        v-for="c in chapters"
                        :key="c.key"
                        class="flex items-center gap-2 rounded-md px-3 py-2 text-left text-sm transition"
                        :class="[
                            c.key === selected
                                ? 'bg-amber-500 font-bold text-white'
                                : c.unlocked
                                  ? 'hover:bg-muted'
                                  : 'cursor-not-allowed opacity-60',
                        ]"
                        :disabled="!c.unlocked"
                        :aria-current="c.key === selected ? 'page' : undefined"
                        @click="open(c)"
                    >
                        <span class="w-6 shrink-0 text-right font-pixel">{{ c.number }}</span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate">{{ c.unlocked ? c.title : '???' }}</span>
                            <span v-if="!c.unlocked" class="block text-[11px]">{{ requirement(c) }}</span>
                        </span>
                        <Lock v-if="!c.unlocked" class="size-3.5 shrink-0" />
                        <span v-else-if="!c.read" class="shrink-0 rounded-full bg-rose-500 px-1.5 text-[10px] font-bold text-white">neu</span>
                    </button>
                </nav>

                <article v-if="chapter" class="hh-panel hh-parchment flex flex-col gap-4 p-5">
                    <header class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-wide text-muted-foreground">Kapitel {{ chapter.number }}</p>
                            <h2 class="font-pixel text-3xl leading-tight">{{ chapter.title }}</h2>
                            <p v-if="chapter.together" class="text-xs font-semibold text-emerald-700 dark:text-emerald-300">
                                Gemeinsam im Familienrat aufgeschlagen
                            </p>
                        </div>
                        <template v-if="canSpeak">
                            <Button v-if="!speaking" variant="outline" @click="speak"><Volume2 class="size-4" /> Vorlesen</Button>
                            <Button v-else variant="outline" @click="stop"><Square class="size-4" /> Stopp</Button>
                        </template>
                    </header>
                    <img
                        v-if="chapter.image"
                        :src="chapter.image"
                        alt=""
                        class="mx-auto max-h-56 w-auto object-contain [image-rendering:pixelated]"
                        loading="lazy"
                    />
                    <div class="mx-auto flex max-w-prose flex-col gap-3 text-lg leading-relaxed">
                        <p v-for="(p, i) in chapter.text" :key="i">{{ p }}</p>
                    </div>
                </article>

                <div v-else class="hh-panel flex items-center gap-3 p-5 text-muted-foreground">
                    <Lock class="size-6" />
                    <p>Das erste Kapitel öffnet sich ab Level {{ chapters[0]?.unlock.level ?? 2 }}. Du bist gerade Level {{ level }}.</p>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
