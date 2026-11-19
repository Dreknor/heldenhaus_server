<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';

defineProps<{
    summary: {
        families: number;
        active_families: number;
        members: { child: number; teen: number; adult: number; parents: number };
        activity: Record<string, { people: number; avg_points: number | null; default_points: number }>;
        quests: Record<string, { count: number; won: number; win_rate: number | null; avg_progress: number | null }>;
        metrics: {
            active_days_per_week: number;
            dailies_rate: number | null;
            family_tasks: { done: number; on_time_rate: number | null };
            stars: { earned: number; redeemed: number };
        };
        mood: {
            answers: number;
            distribution: Record<string, number>;
            average: number | null;
            reasons: { key: string; label: string; count: number }[];
        };
        baseline: { people: number; with_follow_up: number; questions: { key: string; text: string; before: number | null; after: number | null }[] };
        settings: { factors: Record<string, number>; reward_factor: Record<string, number> };
    };
}>();

const groups: Record<string, string> = { child: 'Kinder (9–12)', teen: 'Teens', adult: 'Erwachsene' };
const levels: Record<string, string> = { easy: 'Leicht', normal: 'Normal', hard: 'Schwer' };
const smileys = ['😞', '🙁', '😐', '🙂', '😄'];
const pct = (v: number | null) => (v === null ? '–' : `${Math.round(v * 100)} %`);
</script>

<template>
    <Head title="Zahlen aller Familien" />

    <AppLayout :breadcrumbs="[{ title: 'Zahlen aller Familien', href: '/admin/insights' }]">
        <div class="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
            <div>
                <h1 class="text-2xl font-extrabold tracking-tight">Zahlen aller Familien</h1>
                <p class="text-sm text-muted-foreground">
                    Nur zusammengefasst (Anzahlen und Mittelwerte), ohne Namen und Titel – zum Anpassen der Standardwerte.
                </p>
            </div>

            <section class="grid gap-3 sm:grid-cols-4">
                <div class="rounded-xl border bg-card p-4">
                    <p class="text-2xl font-extrabold">{{ summary.families }}</p>
                    <p class="text-xs text-muted-foreground">Familien ({{ summary.active_families }} aktiv in 14 Tagen)</p>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <p class="text-2xl font-extrabold">{{ summary.members.child + summary.members.teen + summary.members.adult }}</p>
                    <p class="text-xs text-muted-foreground">
                        {{ summary.members.child }} Kinder · {{ summary.members.teen }} Teens · {{ summary.members.adult }} Erwachsene
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <p class="text-2xl font-extrabold">{{ summary.metrics.active_days_per_week }}</p>
                    <p class="text-xs text-muted-foreground">aktive Tage / Woche je Person</p>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <p class="text-2xl font-extrabold">{{ pct(summary.metrics.dailies_rate) }}</p>
                    <p class="text-xs text-muted-foreground">Dailies erledigt (14 Tage)</p>
                </div>
            </section>

            <section class="grid gap-2">
                <h2 class="font-extrabold">Aktivitätswert je Altersgruppe</h2>
                <div class="overflow-x-auto rounded-xl border bg-card">
                    <table class="w-full text-sm">
                        <thead class="bg-muted/60 text-left text-xs uppercase text-muted-foreground">
                            <tr>
                                <th class="p-3">Gruppe</th>
                                <th class="p-3">Personen mit Daten</th>
                                <th class="p-3">Ø Tagespunkte (Median)</th>
                                <th class="p-3">Standardwert</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="(a, key) in summary.activity" :key="key">
                                <td class="p-3 font-semibold">{{ groups[key] ?? key }}</td>
                                <td class="p-3 tabular-nums">{{ a.people }}</td>
                                <td class="p-3 tabular-nums">{{ a.avg_points ?? '–' }}</td>
                                <td class="p-3 tabular-nums">{{ a.default_points }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="grid gap-2">
                <h2 class="font-extrabold">Quests nach Schwierigkeit (180 Tage)</h2>
                <div class="overflow-x-auto rounded-xl border bg-card">
                    <table class="w-full text-sm">
                        <thead class="bg-muted/60 text-left text-xs uppercase text-muted-foreground">
                            <tr>
                                <th class="p-3">Stufe</th>
                                <th class="p-3">Faktor</th>
                                <th class="p-3">Quests</th>
                                <th class="p-3">Gewonnen</th>
                                <th class="p-3">Siegquote</th>
                                <th class="p-3">Ø geschafft</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="(q, key) in summary.quests" :key="key">
                                <td class="p-3 font-semibold">{{ levels[key] ?? key }}</td>
                                <td class="p-3 tabular-nums">{{ summary.settings.factors[key] }}</td>
                                <td class="p-3 tabular-nums">{{ q.count }}</td>
                                <td class="p-3 tabular-nums">{{ q.won }}</td>
                                <td class="p-3 tabular-nums">{{ pct(q.win_rate) }}</td>
                                <td class="p-3 tabular-nums">{{ pct(q.avg_progress) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="grid gap-3 md:grid-cols-2">
                <div class="grid gap-2 rounded-xl border bg-card p-4">
                    <h2 class="font-extrabold">Stimmung (90 Tage, {{ summary.mood.answers }} Antworten)</h2>
                    <div class="flex gap-3 text-sm">
                        <span v-for="(s, i) in smileys" :key="i" class="tabular-nums">{{ s }} {{ summary.mood.distribution[i + 1] ?? 0 }}</span>
                    </div>
                    <p class="text-sm">Schnitt: {{ summary.mood.average ?? '–' }}</p>
                    <p v-for="r in summary.mood.reasons" :key="r.key" class="text-sm">{{ r.label }}: {{ r.count }}</p>
                </div>
                <div class="grid gap-2 rounded-xl border bg-card p-4">
                    <h2 class="font-extrabold">
                        Ausgangslage ({{ summary.baseline.people }} Eltern, {{ summary.baseline.with_follow_up }} mit Vergleich)
                    </h2>
                    <p v-for="q in summary.baseline.questions" :key="q.key" class="text-sm">
                        {{ q.text }} <b class="tabular-nums">{{ q.before ?? '–' }} → {{ q.after ?? '–' }}</b>
                    </p>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
