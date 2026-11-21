<script setup lang="ts">
import Emoji from '@/components/game/Emoji.vue';
import { useFeatures } from '@/composables/useFeatures';
import type { FeatureKey } from '@/types';
import { Link } from '@inertiajs/vue3';
import { Lock } from 'lucide-vue-next';
import { computed } from 'vue';

/** Platzhalter für einen noch gesperrten Teil einer Seite (§13): was kommt und ab welchem Level – ohne die Inhalte. */
const props = defineProps<{ feature: FeatureKey; compact?: boolean }>();

const { entry } = useFeatures();
const f = computed(() => entry(props.feature));
</script>

<template>
    <div
        v-if="f"
        class="hh-panel hh-parchment flex items-center gap-3"
        :class="compact ? 'p-3' : 'p-4 sm:p-5'"
        role="note"
        :aria-label="`${f.title} ist noch gesperrt`"
    >
        <span class="relative shrink-0" :class="compact ? 'text-3xl' : 'text-5xl'">
            <span class="opacity-60 grayscale"><Emoji :glyph="f.emoji" /></span>
            <Lock class="absolute -bottom-1 -right-1 size-5 rounded-full bg-[var(--wood-edge)] p-0.5 text-[var(--gold)]" />
        </span>
        <div class="min-w-0 flex-1">
            <p class="font-extrabold">{{ f.title }}</p>
            <p class="text-sm font-semibold">
                <template v-if="f.state === 'deferred'">Das schalten deine Eltern etwas später frei.</template>
                <template v-else>Schaltest du mit Level {{ f.level }} frei.</template>
            </p>
            <p v-if="f.teaser && !compact" class="text-sm text-muted-foreground">{{ f.teaser }}</p>
        </div>
        <Link
            v-if="!compact"
            :href="route('features.book')"
            class="hh-button shrink-0 border-[var(--wood-edge)] bg-[var(--gold)] px-2 py-1 text-xs text-[var(--wood-edge)] hover:brightness-110"
        >
            Heldenbuch
        </Link>
    </div>
</template>
