<script setup lang="ts">
import Emoji from '@/components/game/Emoji.vue';
import { router } from '@inertiajs/vue3';

export interface FeedEntryView {
    id: number;
    type: string;
    icon: string;
    text: string;
    user: { id: number; name: string; avatar_color: string } | null;
    created_at: string;
    reactions: { emoji: string; count: number; mine: boolean; names: string[] }[];
}

defineProps<{ entries: FeedEntryView[] }>();

function react(entry: FeedEntryView, emoji: string) {
    router.post(route('feed.react', { entry: entry.id }), { emoji }, { preserveScroll: true, only: ['feed'] });
}

function ago(iso: string): string {
    const minutes = Math.round((Date.now() - new Date(iso).getTime()) / 60000);
    if (minutes < 1) return 'gerade eben';
    if (minutes < 60) return `vor ${minutes} Min.`;
    if (minutes < 1440) return `vor ${Math.round(minutes / 60)} Std.`;
    return new Date(iso).toLocaleDateString('de-DE');
}
</script>

<template>
    <div class="grid gap-2">
        <p v-if="!entries.length" class="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">Noch ist nichts passiert – erledigt eure ersten Familienaufgaben!</p>
        <article v-for="e in entries" :key="e.id" class="flex gap-3 rounded-xl border bg-card p-3">
            <span class="text-2xl leading-none"><Emoji :glyph="e.icon" /></span>
            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold">{{ e.text }}</p>
                <p class="text-xs text-muted-foreground">{{ ago(e.created_at) }}</p>
                <div class="mt-1.5 flex flex-wrap gap-1">
                    <button
                        v-for="r in e.reactions"
                        :key="r.emoji"
                        class="flex items-center gap-1 rounded-full border px-2 py-0.5 text-sm transition"
                        :class="r.mine ? 'border-primary bg-primary/10' : r.count ? '' : 'opacity-40 hover:opacity-100'"
                        :title="r.names.join(', ')"
                        @click="react(e, r.emoji)"
                    >
                        {{ r.emoji }}<span v-if="r.count" class="text-xs font-bold">{{ r.count }}</span>
                    </button>
                </div>
            </div>
        </article>
    </div>
</template>
