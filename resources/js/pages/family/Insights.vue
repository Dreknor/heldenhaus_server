<script setup lang="ts">
import Emoji from '@/components/game/Emoji.vue';
import GamePanel from '@/components/game/GamePanel.vue';
import SurveyCard, { type DueSurvey } from '@/components/game/SurveyCard.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';
import { ArrowRight, HeartPulse, Lightbulb, Scale } from 'lucide-vue-next';

interface Aggregate {
    active_days_per_week: number;
    dailies_rate: number | null;
    family_tasks: { done: number; with_due: number; on_time: number; on_time_rate: number | null };
    mastered: number | null;
    goals_reached: number;
    stars: { earned: number; redeemed: number };
}

defineProps<{
    summary: {
        period: { from: string; to: string };
        members: (Aggregate & {
            id: number;
            name: string;
            avatar_color: string;
            role: string;
            longest_break: { days: number; returned: boolean };
        })[];
        family: Aggregate;
        goals_supported: boolean;
        mastery_supported: boolean;
    };
    hints: { type: string; member_id: number; name: string; icon: string; title: string; text: string; suggestions: string[] }[];
    compare: {
        baseline: {
            rounds: number;
            first_date: string | null;
            last_date: string | null;
            questions: { key: string; text: string; better: 'up' | 'down'; before: number | null; after: number | null }[];
        };
        metrics: { before: Aggregate & { from: string }; after: Aggregate & { from: string } } | null;
    };
    survey: DueSurvey | null;
}>();

const pct = (v: number | null) => (v === null ? '–' : `${Math.round(v * 100)} %`);
const fmt = (iso: string | null) => (iso ? new Date(iso).toLocaleDateString('de-DE') : '');
const improved = (q: { better: 'up' | 'down'; before: number | null; after: number | null }) =>
    q.before !== null && q.after !== null && (q.better === 'up' ? q.after > q.before : q.after < q.before);
</script>

<template>
    <Head title="So läuft's" />

    <AppLayout :breadcrumbs="[{ title: 'So läuft\'s', href: '/family/insights' }]">
        <div class="flex min-w-0 flex-1 flex-col gap-5 p-4 md:p-6">
            <div>
                <h1 class="font-pixel text-2xl tracking-wide">So läuft's</h1>
                <p class="text-sm text-muted-foreground">
                    Kennzahlen der letzten 14 Tage ({{ fmt(summary.period.from) }} – {{ fmt(summary.period.to) }}). Alles bleibt bei euch auf dem
                    Server. Die App ändert nie etwas von selbst – Hinweise sind nur Vorschläge.
                </p>
            </div>

            <SurveyCard v-if="survey" :survey="survey" />

            <GamePanel v-if="hints.length" title="Hinweise" subtitle="Ideen, keine Pflicht" :icon="Lightbulb">
                <article v-for="(h, i) in hints" :key="i" class="grid gap-1 rounded-md border-2 border-[var(--parchment-edge)] bg-background/60 p-3">
                    <p class="font-bold"><Emoji :glyph="h.icon" /> {{ h.title }}</p>
                    <p class="text-sm">{{ h.text }}</p>
                    <ul class="list-disc pl-5 text-sm">
                        <li v-for="(s, j) in h.suggestions" :key="j">{{ s }}</li>
                    </ul>
                </article>
            </GamePanel>

            <div class="overflow-x-auto rounded-xl border bg-card">
                <table class="w-full text-sm">
                    <thead class="bg-muted/60 text-left text-xs uppercase text-muted-foreground">
                        <tr>
                            <th class="p-3">Wer</th>
                            <th class="p-3" title="Bindung">Aktive Tage / Woche</th>
                            <th class="p-3" title="Gewohnheitsstärke">Dailies</th>
                            <th class="p-3" title="Selbständigkeit: erledigt bis zur Fälligkeit">Familienaufgaben pünktlich</th>
                            <th v-if="summary.mastery_supported" class="p-3">Gemeistert</th>
                            <th v-if="summary.goals_supported" class="p-3">Ziele erreicht</th>
                            <th class="p-3">Längste Pause</th>
                            <th class="p-3">Sterne verdient / eingelöst</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="m in summary.members" :key="m.id">
                            <td class="p-3 font-semibold">
                                <span class="mr-1.5 inline-block size-3 rounded-full" :style="{ background: m.avatar_color }" />{{ m.name }}
                            </td>
                            <td class="p-3 tabular-nums">{{ m.active_days_per_week }}</td>
                            <td class="p-3 tabular-nums">{{ pct(m.dailies_rate) }}</td>
                            <td class="p-3 tabular-nums">
                                <template v-if="m.family_tasks.with_due">{{ m.family_tasks.on_time }} / {{ m.family_tasks.with_due }}</template>
                                <template v-else>{{ m.family_tasks.done }} erledigt</template>
                            </td>
                            <td v-if="summary.mastery_supported" class="p-3 tabular-nums">{{ m.mastered ?? 0 }}</td>
                            <td v-if="summary.goals_supported" class="p-3 tabular-nums">{{ m.goals_reached }}</td>
                            <td class="p-3">
                                <template v-if="m.longest_break.days"
                                    >{{ m.longest_break.days }} Tage{{ m.longest_break.returned ? ' – wieder da 👋' : '' }}</template
                                >
                                <template v-else>keine</template>
                            </td>
                            <td class="p-3 tabular-nums">{{ m.stars.earned }} / {{ m.stars.redeemed }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <GamePanel title="Vorher – nachher" :icon="Scale">
                <template v-if="compare.baseline.rounds">
                    <p class="text-xs text-muted-foreground">
                        Start: {{ fmt(compare.baseline.first_date)
                        }}<template v-if="compare.baseline.last_date"> · zuletzt: {{ fmt(compare.baseline.last_date) }}</template>
                        (Schnitt eurer Antworten, 1–5)
                    </p>
                    <div v-for="q in compare.baseline.questions" :key="q.key" class="flex flex-wrap items-center gap-2 text-sm">
                        <span class="min-w-60 flex-1 font-semibold">{{ q.text }}</span>
                        <span class="tabular-nums">{{ q.before ?? '–' }}</span>
                        <ArrowRight class="size-4" />
                        <span class="font-bold tabular-nums">{{ q.after ?? 'kommt nach 8 Wochen' }}</span>
                        <span v-if="improved(q)" title="besser geworden">🌱</span>
                    </div>
                </template>
                <p v-else class="text-sm text-muted-foreground">Beantwortet die drei Startfragen, dann seht ihr hier später den Vergleich.</p>

                <div v-if="compare.metrics" class="grid gap-1 border-t-2 border-dashed border-[var(--parchment-edge)] pt-2 text-sm">
                    <p class="font-bold">Erste zwei Wochen ({{ fmt(compare.metrics.before.from) }}) und jetzt</p>
                    <p>
                        Aktive Tage / Woche: {{ compare.metrics.before.active_days_per_week }} →
                        <b>{{ compare.metrics.after.active_days_per_week }}</b>
                    </p>
                    <p>
                        Dailies: {{ pct(compare.metrics.before.dailies_rate) }} → <b>{{ pct(compare.metrics.after.dailies_rate) }}</b>
                    </p>
                    <p>
                        Familienaufgaben pünktlich: {{ pct(compare.metrics.before.family_tasks.on_time_rate) }} →
                        <b>{{ pct(compare.metrics.after.family_tasks.on_time_rate) }}</b>
                    </p>
                </div>
            </GamePanel>

            <p class="flex items-center gap-1.5 text-xs text-muted-foreground">
                <HeartPulse class="size-3.5" /> Familie gesamt: {{ summary.family.active_days_per_week }} aktive Tage pro Woche und Person · Dailies
                {{ pct(summary.family.dailies_rate) }} · {{ summary.family.stars.earned }} Sterne verdient
            </p>
        </div>
    </AppLayout>
</template>
