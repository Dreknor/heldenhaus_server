<script setup lang="ts">
import Emoji from '@/components/game/Emoji.vue';
import type { EventSummary } from '@/types/events';
import { Link } from '@inertiajs/vue3';
import { ChevronRight, Star } from 'lucide-vue-next';

/**
 * Event-Banner auf dem Heldenpanel (§12.7): Familienziel mit Stufen-Symbolen und Zeit als Balken statt Countdown (§11).
 * Bewusst ruhig: keine Animation, kein Blinken.
 */
defineProps<{ events: EventSummary[] }>();

const reached = (event: EventSummary) => event.tiers.filter((tier) => tier.reached).length;
</script>

<template>
    <section v-if="events.length" class="hh-panel hh-parchment flex flex-col gap-1 p-2 sm:p-3" aria-label="Laufende Events">
        <Link
            v-for="event in events.slice(0, 3)"
            :key="event.id"
            :href="route('web.events.show', { event: event.id })"
            class="flex items-center gap-3 rounded-md p-1.5 hover:bg-black/5 dark:hover:bg-white/5"
        >
            <span class="shrink-0 text-3xl" aria-hidden="true"><Emoji :glyph="event.icon" /></span>
            <div class="grid min-w-0 flex-1 gap-1">
                <p class="flex flex-wrap items-center gap-x-2 font-bold leading-tight">
                    {{ event.name }}
                    <span v-if="event.subject_name" class="text-sm font-semibold text-muted-foreground">für {{ event.subject_name }}</span>
                    <span
                        v-if="event.ending_soon"
                        class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800 dark:bg-amber-950 dark:text-amber-200"
                        >Endet bald</span
                    >
                </p>
                <template v-if="event.goal_percent !== null">
                    <div
                        class="relative h-2.5 rounded-full bg-black/10 dark:bg-white/10"
                        role="progressbar"
                        :aria-valuenow="event.goal_percent"
                        aria-valuemin="0"
                        aria-valuemax="100"
                        :aria-label="`Familienziel ${event.name}`"
                    >
                        <div class="h-full rounded-full bg-amber-500" :style="{ width: `${event.goal_percent}%` }" />
                        <Star
                            v-for="tier in event.tiers"
                            :key="tier.percent"
                            class="absolute top-1/2 size-3.5 -translate-x-1/2 -translate-y-1/2 stroke-[2.5]"
                            :class="tier.reached ? 'fill-amber-300 text-amber-700' : 'fill-white text-slate-400 dark:fill-slate-800'"
                            :style="{ left: `${Math.min(tier.percent, 98)}%` }"
                            aria-hidden="true"
                        />
                    </div>
                    <p class="text-xs text-muted-foreground">
                        Familienziel {{ event.goal_percent }} %<template v-if="event.tiers.length">
                            · Stufe {{ reached(event) }} von {{ event.tiers.length }}</template
                        >
                    </p>
                </template>
                <div class="flex items-center gap-2 text-[11px] text-muted-foreground">
                    <span>Zeit</span>
                    <div class="h-1 flex-1 rounded-full bg-black/10 dark:bg-white/10" aria-hidden="true">
                        <div class="h-full rounded-full bg-slate-400" :style="{ width: `${Math.round(event.time_progress * 100)}%` }" />
                    </div>
                </div>
            </div>
            <ChevronRight class="size-4 shrink-0 opacity-60" aria-hidden="true" />
            <span class="sr-only">Zur Event-Seite</span>
        </Link>
    </section>
</template>
