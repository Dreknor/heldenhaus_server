<script setup lang="ts">
import Emoji from '@/components/game/Emoji.vue';
import GamePanel from '@/components/game/GamePanel.vue';
import type { SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { BookOpen, Coins, Sparkles } from 'lucide-vue-next';
import { computed } from 'vue';

/**
 * Vorschau auf dem Heldenpanel (§13.3): was als Nächstes kommt, mit Balken bis dahin, und die offenen
 * ersten Mini-Aufgaben. Ohne Druck: keine Fristen, nur Vorfreude.
 */
withDefaults(defineProps<{ showBookLink?: boolean }>(), { showBookLink: true });

const page = usePage<SharedData>();
const features = computed(() => page.props.features);
const stats = computed(() => page.props.auth.stats);
const next = computed(() => features.value?.next ?? null);
const starters = computed(() => features.value?.starters ?? []);

const progress = computed(() => {
    if (!next.value || !stats.value) return 0;
    const span = Math.max(1, next.value.level - 1);
    const done = stats.value.level - 1 + Math.min(1, stats.value.xp / Math.max(1, stats.value.xp_to_next));
    return Math.max(0, Math.min(100, (done / span) * 100));
});
</script>

<template>
    <GamePanel
        v-if="features?.enforced && (next || starters.length)"
        title="Deine nächsten Schritte"
        :icon="BookOpen"
        :href="showBookLink ? route('features.book') : undefined"
        link-label="Heldenbuch"
    >
        <div v-if="starters.length" class="grid gap-2">
            <p class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Erste Mini-Aufgaben</p>
            <Link
                v-for="s in starters.slice(0, 3)"
                :key="s.key"
                :href="s.href"
                class="flex items-center gap-2 rounded-md border-2 border-[var(--parchment-edge)] bg-background/50 p-2 text-sm hover:bg-background/80"
            >
                <span class="text-2xl"><Emoji :glyph="s.emoji" /></span>
                <span class="min-w-0 flex-1">
                    <span class="block font-bold">{{ s.goal }}</span>
                    <span class="flex flex-wrap items-center gap-2 text-xs font-semibold text-amber-700 dark:text-amber-300">
                        <span v-if="s.rewards?.gold" class="flex items-center gap-0.5"><Coins class="size-3" /> {{ s.rewards.gold }}</span>
                        <span v-if="s.rewards?.xp" class="flex items-center gap-0.5"><Sparkles class="size-3" /> {{ s.rewards.xp }} XP</span>
                        <span class="text-muted-foreground">{{ s.title }}</span>
                    </span>
                </span>
            </Link>
            <p v-if="starters.length > 3" class="text-xs text-muted-foreground">und {{ starters.length - 3 }} weitere im Heldenbuch</p>
        </div>

        <div v-if="next" class="flex items-center gap-3">
            <span class="relative shrink-0 text-4xl">
                <span class="opacity-50 grayscale"><Emoji :glyph="next.emoji" /></span>
            </span>
            <div class="min-w-0 flex-1">
                <p class="text-sm font-extrabold">Ab Level {{ next.level }}: {{ next.title }}</p>
                <p v-if="next.teaser" class="text-xs text-muted-foreground">{{ next.teaser }}</p>
                <div class="mt-1.5 h-2 overflow-hidden rounded-sm border border-black/40 bg-black/15" :title="`noch ${next.levels_to_go} Level`">
                    <div class="h-full bg-gradient-to-r from-amber-500 to-yellow-300" :style="{ width: `${progress}%` }" />
                </div>
            </div>
        </div>
    </GamePanel>
</template>
