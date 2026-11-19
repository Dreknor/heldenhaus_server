<script setup lang="ts">
import Emoji from '@/components/game/Emoji.vue';
import type { FunEvent } from '@/types/events';
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

/** Ein kleines Zufallsereignis (§12.5) mit seiner Aktion: antippen, streicheln, wünschen, suchen … */
const props = defineProps<{ event: FunEvent }>();

const busy = ref(false);

const ROOMS: Record<string, string> = {
    living: 'Wohnzimmer',
    bedroom: 'Schlafzimmer',
    garden: 'Garten',
    workshop: 'Werkstatt',
    stable: 'Stall',
    library: 'Bibliothek',
};

const LABELS: Record<string, string> = {
    tap: 'Antippen',
    save_sticker: 'Ins Gästebuch kleben',
    save_postcard: 'Ins Gästebuch hängen',
    high_five: 'Abklatschen 🙌',
    pop: 'Blasen zerplatzen',
    none: 'Schön!',
};

function interact(payload: Record<string, string | number> = {}) {
    if (busy.value) return;
    busy.value = true;
    router.post(route('web.fun-events.interact', { instance: props.event.id }), payload, {
        preserveScroll: true,
        onFinish: () => (busy.value = false),
    });
}

const button = 'hh-button border-[#2c4a14] bg-primary px-3 py-1 text-sm text-primary-foreground hover:brightness-110 disabled:opacity-60';
const choice =
    'rounded-md border-2 border-[var(--parchment-edge)] bg-background/60 px-2.5 py-1 text-sm font-semibold hover:bg-muted disabled:opacity-60';
</script>

<template>
    <article class="hh-panel hh-parchment flex items-start gap-3 p-3" :aria-label="event.name">
        <span class="shrink-0 text-3xl" aria-hidden="true"><Emoji :glyph="event.icon" /></span>
        <div class="grid min-w-0 flex-1 gap-2">
            <p class="flex flex-wrap items-center gap-2 font-bold">
                {{ event.name }}
                <span v-if="event.family" class="rounded-full bg-muted px-2 py-0.5 text-xs font-semibold text-muted-foreground">Für alle</span>
            </p>
            <p class="text-sm">{{ event.text }}</p>
            <p v-if="event.line" class="text-sm italic">„{{ event.line }}“</p>
            <p v-if="event.postcard" class="rounded-md border-2 border-dashed border-[var(--parchment-edge)] bg-background/50 p-2 text-sm italic">
                {{ event.postcard }}
            </p>

            <div v-if="event.action === 'wish' && event.wishes" class="flex flex-wrap gap-1.5">
                <button v-for="(wish, i) in event.wishes" :key="wish" :class="choice" :disabled="busy" @click="interact({ wish: i })">
                    {{ wish }}
                </button>
            </div>
            <div v-else-if="event.action === 'find' && event.rooms" class="flex flex-wrap gap-1.5">
                <span class="w-full text-xs text-muted-foreground">Wo liegt der Hut?</span>
                <button v-for="room in event.rooms" :key="room" :class="choice" :disabled="busy" @click="interact({ room })">
                    {{ ROOMS[room] ?? room }}
                </button>
            </div>
            <div v-else>
                <button v-if="event.action === 'pet' && event.taps" :class="button" :disabled="busy" @click="interact()">
                    Streicheln ({{ event.taps.done }}/{{ event.taps.needed }})
                </button>
                <button v-else :class="button" :disabled="busy" @click="interact()">
                    {{ event.action === 'tap' && event.first_wins ? 'Schnell fangen!' : (LABELS[event.action] ?? 'Ansehen') }}
                </button>
            </div>
        </div>
    </article>
</template>
