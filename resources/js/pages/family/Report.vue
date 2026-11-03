<script setup lang="ts">
import Emoji from '@/components/game/Emoji.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    report: {
        family: string;
        week_start: string;
        week_end: string;
        members: {
            id: number;
            name: string;
            avatar_color: string;
            dailies_due: number;
            dailies_done: number;
            tasks_done: number;
            family_tasks_done: number;
            family_share: number;
            stars: number;
            xp: number;
            quest_progress: number;
            knocked_out: number;
        }[];
        quests: { name: string; icon: string; status: string; progress: number; goal: number }[];
        pending_approvals: number;
    };
}>();

const fmt = (iso: string) => new Date(iso).toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit' });
const shiftWeek = (days: number) => {
    const d = new Date(props.report.week_start);
    d.setDate(d.getDate() + days);
    router.get(route('family.report'), { week: d.toISOString().slice(0, 10) }, { preserveScroll: true });
};
const isCurrentWeek = computed(() => new Date(props.report.week_end) >= new Date(new Date().toDateString()));
const statusLabel: Record<string, string> = { completed: '✅ geschafft', active: '⏳ läuft', expired: '⌛ abgelaufen', abandoned: '✖ abgebrochen' };
</script>

<template>
    <Head title="Wochenbericht" />

    <AppLayout :breadcrumbs="[{ title: 'Wochenbericht', href: '/family/report' }]">
        <div class="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="flex-1 text-2xl font-extrabold tracking-tight">Wochenbericht</h1>
                <button class="rounded-md p-2 hover:bg-muted" aria-label="Vorige Woche" @click="shiftWeek(-7)"><ChevronLeft class="size-5" /></button>
                <span class="font-semibold">{{ fmt(report.week_start) }} – {{ fmt(report.week_end) }}</span>
                <button class="rounded-md p-2 hover:bg-muted disabled:opacity-30" aria-label="Nächste Woche" :disabled="isCurrentWeek" @click="shiftWeek(7)">
                    <ChevronRight class="size-5" />
                </button>
            </div>

            <p v-if="report.pending_approvals" class="rounded-xl bg-amber-100 px-4 py-2 text-sm font-semibold text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">
                {{ report.pending_approvals }} Freigaben warten noch auf euch.
            </p>

            <!-- Fairness: Wer hat wie viele Familienaufgaben übernommen? -->
            <section class="grid gap-3 rounded-2xl border bg-card p-5">
                <h2 class="font-extrabold">Wer hat bei den Familienaufgaben mitgeholfen?</h2>
                <div class="flex h-6 overflow-hidden rounded-full bg-muted">
                    <div
                        v-for="m in report.members.filter((m) => m.family_share > 0)"
                        :key="m.id"
                        class="h-full"
                        :style="{ width: `${m.family_share}%`, background: m.avatar_color }"
                        :title="`${m.name}: ${m.family_share} %`"
                    />
                </div>
                <div class="flex flex-wrap gap-3 text-sm">
                    <span v-for="m in report.members" :key="m.id" class="flex items-center gap-1.5">
                        <span class="size-3 rounded-full" :style="{ background: m.avatar_color }" /> {{ m.name }} {{ m.family_share }} %
                    </span>
                </div>
            </section>

            <div class="overflow-x-auto rounded-xl border bg-card">
                <table class="w-full text-sm">
                    <thead class="bg-muted/60 text-left text-xs uppercase text-muted-foreground">
                        <tr>
                            <th class="p-3">Wer</th>
                            <th class="p-3">Dailies</th>
                            <th class="p-3">Aufgaben</th>
                            <th class="p-3">davon Familie</th>
                            <th class="p-3">Sterne</th>
                            <th class="p-3">XP</th>
                            <th class="p-3">Quest-Beitrag</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="m in report.members" :key="m.id">
                            <td class="p-3 font-semibold">{{ m.name }}<span v-if="m.knocked_out" class="ml-1" title="K.O. diese Woche">💫</span></td>
                            <td class="p-3 tabular-nums">
                                {{ m.dailies_done }} / {{ m.dailies_due }}
                                <span v-if="m.dailies_due" class="text-muted-foreground">({{ Math.round((m.dailies_done / m.dailies_due) * 100) }} %)</span>
                            </td>
                            <td class="p-3 tabular-nums">{{ m.tasks_done }}</td>
                            <td class="p-3 tabular-nums">{{ m.family_tasks_done }}</td>
                            <td class="p-3 tabular-nums">{{ m.stars }}</td>
                            <td class="p-3 tabular-nums">{{ m.xp }}</td>
                            <td class="p-3 tabular-nums">{{ m.quest_progress }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <section v-if="report.quests.length" class="grid gap-2">
                <h2 class="font-extrabold">Familien-Quests</h2>
                <div v-for="(q, i) in report.quests" :key="i" class="flex items-center gap-3 rounded-xl border bg-card p-3 text-sm">
                    <span class="text-2xl"><Emoji :glyph="q.icon" /></span>
                    <span class="flex-1 font-semibold">{{ q.name }}</span>
                    <span class="tabular-nums text-muted-foreground">{{ Math.round(q.progress) }} / {{ Math.round(q.goal) }}</span>
                    <span class="font-bold">{{ statusLabel[q.status] }}</span>
                </div>
            </section>
            <p class="text-xs text-muted-foreground">Jeden Sonntag um 18 Uhr kommt der Bericht auch per E-Mail.</p>
        </div>
    </AppLayout>
</template>
