<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, Award, History } from 'lucide-vue-next';

defineProps<{
    events: {
        id: number;
        key: string;
        name: string;
        icon: string;
        starts_at: string;
        ends_at: string;
        points: number;
        own_points: number;
        tiers: number[];
        badges: string[];
    }[];
}>();

const date = (iso: string) => new Date(iso).toLocaleDateString('de-DE', { day: 'numeric', month: 'short', year: 'numeric' });
</script>

<template>
    <Head title="Ereignisverlauf" />

    <AppLayout
        :breadcrumbs="[
            { title: 'Ereignisse', href: '/events' },
            { title: 'Verlauf', href: '/events/history' },
        ]"
    >
        <div class="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
            <div class="flex items-center gap-3">
                <History class="size-6 text-amber-500" />
                <div>
                    <h1 class="text-2xl font-extrabold tracking-tight">Bisherige Ereignisse</h1>
                    <p class="text-sm text-muted-foreground">Die letzten abgeschlossenen Familienereignisse und dein eigener Beitrag.</p>
                </div>
            </div>

            <div v-if="events.length" class="divide-y rounded-2xl border bg-card">
                <article v-for="event in events" :key="event.id" class="flex flex-wrap items-center gap-3 p-4">
                    <span class="text-3xl" aria-hidden="true">{{ event.icon }}</span>
                    <div class="min-w-0 flex-1">
                        <h2 class="font-extrabold">{{ event.name }}</h2>
                        <p class="text-sm text-muted-foreground">{{ date(event.starts_at) }} – {{ date(event.ends_at) }}</p>
                    </div>
                    <div class="text-right text-sm">
                        <p class="font-bold">{{ event.points }} gemeinsame Punkte</p>
                        <p class="text-xs text-muted-foreground">Dein Beitrag: {{ event.own_points }} Punkte</p>
                    </div>
                    <div v-if="event.tiers.length || event.badges.length" class="flex basis-full flex-wrap gap-2 pl-12 text-xs">
                        <span
                            v-for="tier in event.tiers"
                            :key="tier"
                            class="rounded-full bg-amber-100 px-2 py-1 font-semibold text-amber-900 dark:bg-amber-950 dark:text-amber-100"
                        >
                            <Award class="mr-1 inline size-3" /> Stufe {{ tier }} erreicht
                        </span>
                        <span v-for="badge in event.badges" :key="badge" class="rounded-full bg-muted px-2 py-1 font-semibold">{{ badge }}</span>
                    </div>
                </article>
            </div>
            <p v-else class="rounded-2xl border border-dashed p-5 text-sm text-muted-foreground">Noch keine Ereignisse abgeschlossen.</p>

            <Link
                :href="route('web.events.index')"
                class="inline-flex w-fit items-center gap-2 text-sm font-semibold text-muted-foreground hover:text-foreground"
            >
                <ArrowLeft class="size-4" /> Zurück zu den Ereignissen
            </Link>
        </div>
    </AppLayout>
</template>
