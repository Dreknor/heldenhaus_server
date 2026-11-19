<script setup lang="ts">
import Emoji from '@/components/game/Emoji.vue';
import type { SharedData } from '@/types';
import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/** Familienwappen wählen – es erscheint in der Chronik des Heldenhauses (§14.8). */
const crests = ['🛡️', '🦁', '🐉', '🦅', '🐺', '🦊', '🐻', '🦉', '🌳', '⭐', '🌙', '☀️', '🏰', '🌻', '🐢', '🦄'];

const page = usePage<SharedData>();
const current = computed(() => page.props.auth.family?.crest ?? crests[0]);

function choose(crest: string) {
    router.put(route('family.crest'), { crest }, { preserveScroll: true });
}
</script>

<template>
    <section class="rounded-2xl border bg-card p-5">
        <h2 class="font-extrabold">Familienwappen</h2>
        <p class="mb-3 text-sm text-muted-foreground">Euer Zeichen steht in der Chronik des Heldenhauses.</p>
        <div class="flex flex-wrap gap-2" role="radiogroup" aria-label="Wappen">
            <button
                v-for="c in crests"
                :key="c"
                role="radio"
                :aria-checked="c === current"
                class="flex size-12 items-center justify-center rounded-xl border-2 text-2xl transition"
                :class="
                    c === current
                        ? 'border-amber-500 bg-amber-50 dark:bg-amber-950/40'
                        : 'border-transparent bg-muted/50 hover:border-muted-foreground/30'
                "
                @click="choose(c)"
            >
                <Emoji :glyph="c" />
            </button>
        </div>
    </section>
</template>
