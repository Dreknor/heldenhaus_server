<script setup lang="ts">
import Emoji from '@/components/game/Emoji.vue';
import { Link, router } from '@inertiajs/vue3';
import { X } from 'lucide-vue-next';

export interface CouncilHint {
    key: string;
    icon: string;
    text: string;
    href: string;
    dismiss?: string;
}

defineProps<{ hints: CouncilHint[] }>();

function dismiss(key: string) {
    router.post(route('hints.dismiss', { key }), {}, { preserveScroll: true });
}
</script>

<template>
    <div v-if="hints.length" class="grid gap-2 sm:grid-cols-2 xl:grid-cols-3" aria-label="Hinweise">
        <div v-for="hint in hints" :key="hint.key" class="hh-panel hh-parchment flex items-center gap-2 px-3 py-2">
            <span class="text-2xl"><Emoji :glyph="hint.icon" /></span>
            <Link :href="hint.href" class="min-w-0 flex-1 text-sm font-bold leading-tight hover:underline">{{ hint.text }}</Link>
            <button v-if="hint.dismiss" class="rounded-md p-1 hover:bg-muted" aria-label="Ausblenden" @click="dismiss(hint.dismiss)">
                <X class="size-4" />
            </button>
        </div>
    </div>
</template>
