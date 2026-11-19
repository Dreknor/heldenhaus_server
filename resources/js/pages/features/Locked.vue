<script setup lang="ts">
import Emoji from '@/components/game/Emoji.vue';
import GamePanel from '@/components/game/GamePanel.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { FeatureEntry } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { BookOpen, Lock, Swords } from 'lucide-vue-next';
import { computed } from 'vue';

/** Vorschau einer noch gesperrten Seite (§13): was kommt und wann – ohne die Inhalte dahinter. */
const props = defineProps<{
    feature: FeatureEntry;
    others: FeatureEntry[];
    level: number;
    xp: number;
    xpToNext: number;
    dependsOn: FeatureEntry | null;
}>();

const deferred = computed(() => props.feature.state === 'deferred');
const levelsToGo = computed(() => Math.max(0, props.feature.level - props.level));
// Fortschritt bis zum Freischalt-Level: ganze Level plus der Anteil im aktuellen Level
const progress = computed(() => {
    const span = Math.max(1, props.feature.level - 1);
    const done = props.level - 1 + Math.min(1, props.xp / Math.max(1, props.xpToNext));
    return Math.max(0, Math.min(100, (done / span) * 100));
});
</script>

<template>
    <Head :title="feature.title" />

    <AppLayout :breadcrumbs="[{ title: feature.title, href: feature.href }]">
        <div class="mx-auto flex w-full max-w-2xl flex-col gap-5 p-4 md:p-6">
            <GamePanel :title="feature.title" :icon="Lock">
                <div class="flex flex-col items-center gap-3 py-4 text-center">
                    <span class="relative text-7xl">
                        <span class="opacity-50 grayscale"><Emoji :glyph="feature.emoji" /></span>
                        <Lock class="absolute -bottom-1 -right-2 size-9 rounded-full bg-[var(--wood-edge)] p-1.5 text-[var(--gold)]" />
                    </span>
                    <p v-if="deferred" class="text-lg font-extrabold">Das schalten deine Eltern etwas später frei.</p>
                    <p v-else class="text-lg font-extrabold">Schaltest du mit Level {{ feature.level }} frei</p>
                    <p v-if="feature.teaser" class="max-w-md text-sm font-semibold">{{ feature.teaser }}</p>
                    <p v-if="others.length" class="text-xs text-muted-foreground">
                        Hier wartet auch:
                        {{ others.map((o) => (o.state === 'deferred' ? o.title : `${o.title} (Level ${o.level})`)).join(', ') }}
                    </p>
                    <p v-if="dependsOn && !deferred" class="text-xs text-muted-foreground">
                        Vorher kommt noch: <Emoji :glyph="dependsOn.emoji" /> {{ dependsOn.title }}
                    </p>

                    <div v-if="!deferred" class="mt-2 w-full max-w-sm">
                        <div class="mb-1 flex justify-between text-xs font-bold">
                            <span>Du bist Level {{ level }}</span>
                            <span>{{ levelsToGo === 1 ? 'noch 1 Level' : `noch ${levelsToGo} Level` }}</span>
                        </div>
                        <div class="h-3 overflow-hidden rounded-sm border border-black/50 bg-black/20">
                            <div class="h-full bg-gradient-to-r from-amber-500 to-yellow-300" :style="{ width: `${progress}%` }" />
                        </div>
                        <p class="mt-2 text-xs text-muted-foreground">Jede erledigte Aufgabe bringt dich näher. Gesammelt wird schon jetzt.</p>
                    </div>
                </div>
            </GamePanel>

            <div class="flex flex-wrap justify-center gap-2">
                <Link
                    :href="route('dashboard')"
                    class="hh-button border-[var(--wood-edge)] bg-[var(--gold)] px-4 py-2 text-sm text-[var(--wood-edge)] hover:brightness-110"
                >
                    <Swords class="size-4" /> Zu meinen Aufgaben
                </Link>
                <Link :href="route('features.book')" class="hh-button border-[var(--wood-edge)] bg-transparent px-4 py-2 text-sm">
                    <BookOpen class="size-4" /> Heldenbuch
                </Link>
            </div>
        </div>
    </AppLayout>
</template>
