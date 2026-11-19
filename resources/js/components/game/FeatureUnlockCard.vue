<script setup lang="ts">
import Emoji from '@/components/game/Emoji.vue';
import ItemIcon from '@/components/game/ItemIcon.vue';
import type { SharedData } from '@/types';
import { router, usePage } from '@inertiajs/vue3';
import { Coins, Sparkles } from 'lucide-vue-next';
import { computed, ref } from 'vue';

/**
 * Freischalt-Karte (§13.3): erscheint beim nächsten Öffnen nach einer Freischaltung, höchstens eine pro Tag
 * (entscheidet der Server). „Zeig's mir“ öffnet die Funktion mit kurzer Tour, „Später“ schließt sie.
 * Sie liegt unter Level-up- und Erfolgs-Feier, damit diese zuerst gesehen werden.
 */
const page = usePage<SharedData>();
const intro = computed(() => page.props.features?.intro ?? null);
const calm = computed(() => page.props.auth.feedback?.reduce_motion ?? false);
const busy = ref(false);

function close(tour: boolean) {
    if (!intro.value) return;
    busy.value = true;
    router.post(route('features.intro-seen', { key: intro.value.key }), { tour }, { preserveScroll: !tour, onFinish: () => (busy.value = false) });
}
</script>

<template>
    <Transition
        enter-from-class="scale-90 opacity-0"
        leave-to-class="opacity-0"
        enter-active-class="transition duration-300"
        leave-active-class="transition"
    >
        <div
            v-if="intro"
            class="fixed inset-0 z-[45] flex items-center justify-center bg-black/50 p-4"
            role="dialog"
            aria-modal="true"
            aria-labelledby="unlock-title"
            @keydown.esc="close(false)"
        >
            <div class="hh-panel hh-parchment w-full max-w-md p-5 text-center sm:p-6">
                <p class="text-xs font-bold uppercase tracking-widest text-muted-foreground">Neu freigeschaltet!</p>
                <div class="relative mx-auto my-3 flex size-24 items-center justify-center text-6xl">
                    <Emoji :glyph="intro.emoji" />
                    <span class="absolute -right-2 -top-2 text-3xl" :class="calm ? '' : 'hh-unlock-pop'"><Emoji glyph="🔓" /></span>
                </div>
                <h2 id="unlock-title" class="hh-panel-title text-xl">{{ intro.title }}</h2>
                <p class="mt-2 text-sm font-semibold">{{ intro.text }}</p>

                <div v-if="intro.collected?.length" class="mt-3 rounded-md border-2 border-dashed border-[var(--parchment-edge)] p-2 text-sm">
                    <p class="font-bold">Das hast du schon gesammelt:</p>
                    <p class="mt-1 flex flex-wrap justify-center gap-1.5">
                        <span
                            v-for="m in intro.collected.slice(0, 6)"
                            :key="m.key"
                            class="flex items-center gap-1 rounded-full bg-muted py-0.5 pl-0.5 pr-2 text-xs font-bold"
                        >
                            <ItemIcon :item="{ key: m.key, type: 'material' }" :size="20" /> {{ m.quantity }} {{ m.name }}
                        </span>
                    </p>
                </div>

                <div v-if="intro.starter_task" class="mt-3 rounded-md bg-amber-100/70 p-2 text-sm dark:bg-amber-950/40">
                    <p class="font-bold">Erste Mini-Aufgabe: {{ intro.starter_task.goal }}</p>
                    <p
                        v-if="intro.starter_task.rewards"
                        class="mt-0.5 flex items-center justify-center gap-2 text-xs font-semibold text-amber-700 dark:text-amber-300"
                    >
                        <span v-if="intro.starter_task.rewards.gold" class="flex items-center gap-0.5"
                            ><Coins class="size-3.5" /> {{ intro.starter_task.rewards.gold }} Gold</span
                        >
                        <span v-if="intro.starter_task.rewards.xp" class="flex items-center gap-0.5"
                            ><Sparkles class="size-3.5" /> {{ intro.starter_task.rewards.xp }} XP</span
                        >
                        <span>· ohne Frist</span>
                    </p>
                </div>

                <div class="mt-5 flex justify-center gap-2">
                    <button class="hh-button border-[var(--wood-edge)] bg-transparent px-4 py-2 text-sm" :disabled="busy" @click="close(false)">
                        Später
                    </button>
                    <button
                        class="hh-button border-[var(--wood-edge)] bg-[var(--gold)] px-5 py-2 text-sm text-[var(--wood-edge)] hover:brightness-110"
                        :disabled="busy"
                        autofocus
                        @click="close(true)"
                    >
                        Zeig's mir
                    </button>
                </div>
                <p class="mt-3 text-xs text-muted-foreground">Im Heldenbuch kannst du alles später nachlesen.</p>
            </div>
        </div>
    </Transition>
</template>

<style scoped>
@keyframes unlock-pop {
    0% {
        transform: translateY(6px) rotate(-12deg) scale(0.7);
        opacity: 0;
    }
    55% {
        transform: translateY(-8px) rotate(10deg) scale(1.15);
        opacity: 1;
    }
    100% {
        transform: translateY(0) rotate(0) scale(1);
    }
}
.hh-unlock-pop {
    animation: unlock-pop 0.7s ease-out 0.2s both;
}
</style>
