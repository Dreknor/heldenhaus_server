<script setup lang="ts">
import Emoji from '@/components/game/Emoji.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { CalendarDays, Clock3, History, Sparkles } from 'lucide-vue-next';

interface EventCard {
    id: number;
    key: string;
    name: string;
    icon: string;
    category?: string;
    status?: string;
    description: string;
    starts_at: string;
    ends_at?: string;
    ending_soon?: boolean;
    time_progress?: number;
    goal?: { goal: number; points: number; percent: number };
    own?: { points: number };
}

defineProps<{ running: EventCard[]; upcoming: EventCard[]; canManage: boolean }>();

const date = (iso: string) => new Date(iso).toLocaleDateString('de-DE', { day: 'numeric', month: 'long', year: 'numeric' });
</script>

<template>
    <Head title="Familienereignisse" />

    <AppLayout :breadcrumbs="[{ title: 'Ereignisse', href: '/events' }]">
        <div class="flex min-w-0 flex-1 flex-col gap-8 p-4 md:p-6">
            <header class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-extrabold tracking-tight">Familienereignisse</h1>
                    <p class="text-sm text-muted-foreground">Gemeinsame Abenteuer, besondere Tage und kleine Überraschungen.</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Link
                        v-if="canManage"
                        :href="route('web.events.settings')"
                        class="inline-flex items-center gap-2 rounded-lg border px-3 py-2 text-sm font-semibold hover:bg-muted"
                    >
                        Einstellungen
                    </Link>
                    <Link
                        :href="route('web.events.history')"
                        class="inline-flex items-center gap-2 rounded-lg border px-3 py-2 text-sm font-semibold hover:bg-muted"
                    >
                        <History class="size-4" /> Bisherige Ereignisse
                    </Link>
                </div>
            </header>

            <section class="grid gap-3">
                <h2 class="flex items-center gap-2 text-lg font-extrabold"><Sparkles class="size-5 text-amber-500" /> Gerade aktiv</h2>
                <div v-if="running.length" class="grid gap-3 md:grid-cols-2">
                    <Link
                        v-for="event in running"
                        :key="event.id"
                        :href="route('web.events.show', { event: event.id })"
                        class="grid gap-3 rounded-2xl border bg-card p-4 transition hover:border-amber-400"
                    >
                        <div class="flex items-start gap-3">
                            <span class="text-3xl" aria-hidden="true"><Emoji :glyph="event.icon" /></span>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="font-extrabold">{{ event.name }}</h3>
                                    <span
                                        v-if="event.ending_soon"
                                        class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800 dark:bg-amber-950 dark:text-amber-200"
                                        >Endet bald</span
                                    >
                                </div>
                                <p class="mt-1 text-sm text-muted-foreground">{{ event.description }}</p>
                            </div>
                        </div>
                        <template v-if="event.goal">
                            <div class="flex items-center justify-between text-xs font-semibold">
                                <span>Gemeinsamer Fortschritt</span><span>{{ event.goal.points }} / {{ event.goal.goal }} Punkte</span>
                            </div>
                            <div
                                class="h-2 overflow-hidden rounded-full bg-muted"
                                role="progressbar"
                                :aria-valuenow="event.goal.percent"
                                aria-valuemin="0"
                                aria-valuemax="100"
                            >
                                <div class="h-full rounded-full bg-amber-500 transition-all" :style="{ width: `${event.goal.percent}%` }" />
                            </div>
                        </template>
                        <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-muted-foreground">
                            <span class="inline-flex items-center gap-1"><Clock3 class="size-3.5" /> Bis {{ date(event.ends_at!) }}</span>
                            <span v-if="event.own">Dein Beitrag: {{ event.own.points }} Punkte</span>
                        </div>
                    </Link>
                </div>
                <p v-else class="rounded-2xl border border-dashed p-5 text-sm text-muted-foreground">
                    Im Moment läuft kein Ereignis. Erledigte Aufgaben helfen dabei, gemeinsame Ereignisse voranzubringen.
                </p>
            </section>

            <section class="grid gap-3">
                <h2 class="flex items-center gap-2 text-lg font-extrabold"><CalendarDays class="size-5 text-sky-600" /> Demnächst</h2>
                <div v-if="upcoming.length" class="divide-y rounded-2xl border bg-card">
                    <Link
                        v-for="event in upcoming"
                        :key="event.id"
                        :href="route('web.events.show', { event: event.id })"
                        class="flex flex-wrap items-center gap-3 p-4 transition hover:bg-muted/50"
                    >
                        <span class="text-2xl" aria-hidden="true"><Emoji :glyph="event.icon" /></span>
                        <div class="min-w-0 flex-1">
                            <p class="font-bold">{{ event.name }}</p>
                            <p class="text-sm text-muted-foreground">{{ event.description }}</p>
                        </div>
                        <span class="text-sm font-semibold">{{ date(event.starts_at) }}</span>
                    </Link>
                </div>
                <p v-else class="rounded-2xl border border-dashed p-5 text-sm text-muted-foreground">
                    In den nächsten Tagen ist noch kein Kalenderereignis geplant.
                </p>
            </section>
        </div>
    </AppLayout>
</template>
