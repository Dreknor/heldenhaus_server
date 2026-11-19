<script setup lang="ts">
import Emoji from '@/components/game/Emoji.vue';
import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { X } from 'lucide-vue-next';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

/**
 * Kurze Tour (§13.3): hebt höchstens drei Stellen der Seite nacheinander hervor (Elemente mit data-tour="…").
 * Startet per ?tour=schlüssel, lässt sich jederzeit beenden. Fehlt eine Stelle, steht der Hinweis in der Mitte.
 */
const page = usePage<SharedData>();
const tour = computed(() => page.props.tour ?? null);
const calm = computed(() => page.props.auth.feedback?.reduce_motion ?? false);

const index = ref(0);
const closed = ref(false);
const rect = ref<{ top: number; left: number; width: number; height: number } | null>(null);

const step = computed(() => (tour.value && !closed.value ? (tour.value.steps[index.value] ?? null) : null));
const last = computed(() => !!tour.value && index.value >= tour.value.steps.length - 1);

function target(): HTMLElement | null {
    if (!step.value) return null;
    const all = Array.from(document.querySelectorAll<HTMLElement>(`[data-tour="${CSS.escape(step.value.anchor)}"]`));
    return all.find((el) => el.offsetParent !== null || el.getClientRects().length > 0) ?? null;
}

function measure() {
    const el = target();
    if (!el) {
        rect.value = null;
        return;
    }
    const r = el.getBoundingClientRect();
    rect.value = { top: r.top - 6, left: r.left - 6, width: r.width + 12, height: r.height + 12 };
}

async function show() {
    await nextTick();
    const el = target();
    if (el) el.scrollIntoView({ block: 'center', behavior: calm.value ? 'auto' : 'smooth' });
    measure();
    // nach dem Scrollen noch einmal messen
    setTimeout(measure, calm.value ? 0 : 400);
}

function finish() {
    closed.value = true;
    // ?tour aus der Adresse nehmen, damit die Tour nach einer Aktion auf der Seite nicht neu startet
    const url = new URL(window.location.href);
    if (url.searchParams.has('tour')) {
        url.searchParams.delete('tour');
        window.history.replaceState(window.history.state, '', url.toString());
    }
}

function next() {
    if (last.value) finish();
    else index.value++;
}

function onKey(e: KeyboardEvent) {
    if (!step.value) return;
    if (e.key === 'Escape') finish();
    if (e.key === 'ArrowRight' || e.key === 'Enter') next();
}

watch(
    () => tour.value?.key,
    () => {
        index.value = 0;
        closed.value = false;
        if (tour.value) show();
    },
);
watch(index, show);

onMounted(() => {
    window.addEventListener('resize', measure);
    window.addEventListener('scroll', measure, true);
    window.addEventListener('keydown', onKey);
    if (tour.value) show();
});
onBeforeUnmount(() => {
    window.removeEventListener('resize', measure);
    window.removeEventListener('scroll', measure, true);
    window.removeEventListener('keydown', onKey);
});

const bubbleStyle = computed(() => {
    if (!rect.value) return {};
    const below = rect.value.top + rect.value.height + 12;
    const fitsBelow = below + 170 < window.innerHeight;
    const left = Math.max(16, Math.min(rect.value.left, window.innerWidth - 336));
    return fitsBelow ? { top: `${below}px`, left: `${left}px` } : { top: `${Math.max(16, rect.value.top - 182)}px`, left: `${left}px` };
});
</script>

<template>
    <div v-if="step && tour" class="fixed inset-0 z-[44]" role="dialog" aria-modal="true" :aria-label="`Kurze Tour: ${tour.title}`">
        <!-- Abdunkeln mit Loch an der hervorgehobenen Stelle -->
        <div
            v-if="rect"
            class="pointer-events-none fixed rounded-lg ring-4 ring-[var(--gold)]"
            :class="calm ? '' : 'transition-all duration-300'"
            :style="{
                top: `${rect.top}px`,
                left: `${rect.left}px`,
                width: `${rect.width}px`,
                height: `${rect.height}px`,
                boxShadow: '0 0 0 9999px rgb(0 0 0 / 0.5)',
            }"
        />
        <div v-else class="fixed inset-0 bg-black/50" />
        <div class="fixed inset-0" @click="next" />

        <div
            class="hh-panel hh-parchment fixed w-[calc(100vw-2rem)] max-w-xs p-4"
            :class="rect ? '' : 'left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2'"
            :style="bubbleStyle"
            aria-live="polite"
        >
            <div class="mb-1 flex items-start gap-2">
                <p class="flex-1 text-xs font-bold uppercase tracking-wider text-muted-foreground">
                    <Emoji :glyph="tour.emoji" /> {{ tour.title }} · {{ index + 1 }}/{{ tour.steps.length }}
                </p>
                <button class="rounded p-0.5 hover:bg-muted" aria-label="Tour beenden" @click="finish"><X class="size-4" /></button>
            </div>
            <p class="font-semibold">{{ step.text }}</p>
            <div class="mt-3 flex justify-end gap-2">
                <button v-if="!last" class="hh-button border-[var(--wood-edge)] bg-transparent px-3 py-1 text-xs" @click="finish">Beenden</button>
                <button
                    class="hh-button border-[var(--wood-edge)] bg-[var(--gold)] px-3 py-1 text-xs text-[var(--wood-edge)] hover:brightness-110"
                    autofocus
                    @click="next"
                >
                    {{ last ? 'Fertig' : 'Weiter' }}
                </button>
            </div>
        </div>
    </div>
</template>
