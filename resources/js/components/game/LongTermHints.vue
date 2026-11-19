<script setup lang="ts">
import Emoji from '@/components/game/Emoji.vue';
import { Link } from '@inertiajs/vue3';
import { ChevronRight } from 'lucide-vue-next';

/** Knappe Hinweise im Heldenpanel: neues Chronik-Kapitel, neue Album-Belohnung (§14.7, §14.8). */
defineProps<{
    hints?: { chapter: { key: string; number: number; title: string } | null; album_rewards: number } | null;
}>();
</script>

<template>
    <div v-if="hints && (hints.chapter || hints.album_rewards)" class="flex flex-wrap gap-2">
        <Link
            v-if="hints.chapter"
            :href="route('chronicle.index')"
            class="hh-panel hh-parchment flex items-center gap-2 px-3 py-2 text-sm font-bold transition hover:brightness-105"
        >
            <Emoji glyph="📖" /> Neues Kapitel in der Chronik: „{{ hints.chapter.title }}“
            <ChevronRight class="size-4 opacity-70" />
        </Link>
        <Link
            v-if="hints.album_rewards"
            :href="route('album.index')"
            class="hh-panel hh-parchment flex items-center gap-2 px-3 py-2 text-sm font-bold transition hover:brightness-105"
        >
            <Emoji glyph="🎁" /> {{ hints.album_rewards === 1 ? 'Neue Album-Belohnung!' : `${hints.album_rewards} neue Album-Belohnungen!` }}
            <ChevronRight class="size-4 opacity-70" />
        </Link>
    </div>
</template>
