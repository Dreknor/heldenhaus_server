<script setup lang="ts">
import GamePanel from '@/components/game/GamePanel.vue';
import ReviewCard, { type Review } from '@/components/game/ReviewCard.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { CalendarDays, ChartLine, Flame, Trophy, Users } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Week {
    week: string;
    starts_on: string;
    tasks: number;
    xp: number;
}

const props = defineProps<{
    subject: { id: number; name: string; is_self: boolean };
    stats: {
        private_tasks: number;
        weeks: Week[];
        categories: { category: string; tasks: number }[];
        weekdays: { weekday: number; tasks: number }[];
        last_30_days: { tasks: number; xp: number; gold: number; perfect_days: number; dailies_rate: number | null };
        streaks: { task: string | null; type: string; streak: number }[];
        totals: Record<string, number>;
    };
    activity: { points: number; units: number; own: boolean };
    reviews: { week: Review | null; month: Review | null; year: Review | null };
    members: { id: number; name: string; avatar_color: string }[];
    family: {
        weeks: Week[];
        members: { user_id: number; name: string; weeks: number[]; tasks_4_weeks: number }[];
        totals: { tasks_4_weeks: number; family_tasks_4_weeks: number; dungeons_cleared: number; items_crafted: number };
    } | null;
    categories: Record<string, string>;
}>();

const tab = ref<'person' | 'family'>('person');
const maxWeek = computed(() => Math.max(1, ...props.stats.weeks.map((w) => w.tasks)));
const maxWeekday = computed(() => Math.max(1, ...props.stats.weekdays.map((w) => w.tasks)));
const maxCategory = computed(() => Math.max(1, ...props.stats.categories.map((c) => c.tasks)));
const maxFamilyWeek = computed(() => Math.max(1, ...(props.family?.weeks ?? []).map((w) => w.tasks)));
const weekdayNames = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
const totals: { key: string; label: string; icon: string }[] = [
    { key: 'tasks_done', label: 'Aufgaben insgesamt', icon: '✅' },
    { key: 'best_streak', label: 'Beste Serie', icon: '🔥' },
    { key: 'bosses_defeated', label: 'Bosse besiegt', icon: '🐲' },
    { key: 'pets', label: 'Haustiere', icon: '🐾' },
    { key: 'dungeons_cleared', label: 'Dungeons', icon: '🗝️' },
    { key: 'items_crafted', label: 'Hergestellt', icon: '🔨' },
];

function pick(id: number) {
    router.get(route('stats.index'), id === props.subject.id && props.subject.is_self ? {} : { member: id }, { preserveScroll: true });
}
</script>

<template>
    <Head title="Statistik" />

    <AppLayout :breadcrumbs="[{ title: 'Statistik', href: '/stats' }]">
        <div class="flex min-w-0 flex-1 flex-col gap-5 p-4 md:p-6">
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="flex-1 font-pixel text-2xl tracking-wide">{{ subject.is_self ? 'Deine Statistik' : `Statistik von ${subject.name}` }}</h1>
                <div v-if="family" class="hh-panel inline-flex gap-1 p-1" role="tablist">
                    <button
                        v-for="t in [
                            { key: 'person', label: 'Person', icon: ChartLine },
                            { key: 'family', label: 'Familie', icon: Users },
                        ] as const"
                        :key="t.key"
                        role="tab"
                        :aria-selected="tab === t.key"
                        class="flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-bold"
                        :class="tab === t.key ? 'bg-amber-500 text-white shadow' : 'text-muted-foreground hover:text-foreground'"
                        @click="tab = t.key"
                    >
                        <component :is="t.icon" class="size-4" /> {{ t.label }}
                    </button>
                </div>
            </div>

            <template v-if="tab === 'person'">
                <div v-if="members.length > 1" class="flex flex-wrap gap-2">
                    <button
                        v-for="m in members"
                        :key="m.id"
                        class="flex items-center gap-1.5 rounded-full border-2 px-3 py-1 text-sm font-bold"
                        :class="m.id === subject.id ? 'border-amber-500 bg-amber-100 dark:bg-amber-900/40' : 'border-transparent bg-muted'"
                        @click="pick(m.id)"
                    >
                        <span class="size-3 rounded-full" :style="{ background: m.avatar_color }" /> {{ m.name }}
                    </button>
                </div>

                <div class="grid gap-5 lg:grid-cols-3">
                    <ReviewCard v-if="reviews.week" :review="reviews.week" />
                    <ReviewCard v-if="reviews.month" :review="reviews.month" />
                    <ReviewCard v-if="reviews.year" :review="reviews.year" />
                </div>

                <div class="grid gap-3 sm:grid-cols-4">
                    <div class="hh-panel hh-parchment p-3">
                        <p class="text-xs font-bold text-muted-foreground">Letzte 30 Tage</p>
                        <p class="text-2xl font-extrabold">{{ stats.last_30_days.tasks }}</p>
                        <p class="text-xs">Aufgaben</p>
                    </div>
                    <div class="hh-panel hh-parchment p-3">
                        <p class="text-xs font-bold text-muted-foreground">Dailies geschafft</p>
                        <p class="text-2xl font-extrabold">
                            {{ stats.last_30_days.dailies_rate === null ? '–' : `${Math.round(stats.last_30_days.dailies_rate * 100)} %` }}
                        </p>
                        <p class="text-xs">{{ stats.last_30_days.perfect_days }} perfekte Tage</p>
                    </div>
                    <div class="hh-panel hh-parchment p-3">
                        <p class="text-xs font-bold text-muted-foreground">Erfahrung</p>
                        <p class="text-2xl font-extrabold">{{ stats.last_30_days.xp }}</p>
                        <p class="text-xs">XP in 30 Tagen</p>
                    </div>
                    <div
                        class="hh-panel hh-parchment p-3"
                        title="Median der Tagespunkte der letzten 14 Tage – daraus berechnen sich Boss-Lebenspunkte"
                    >
                        <p class="text-xs font-bold text-muted-foreground">Aktivitätswert</p>
                        <p class="text-2xl font-extrabold">{{ Math.round(activity.points) }}</p>
                        <p class="text-xs">{{ activity.own ? 'Punkte pro Tag' : 'Startwert – wächst mit dir' }}</p>
                    </div>
                </div>

                <div class="grid gap-5 lg:grid-cols-2">
                    <GamePanel title="Aufgaben pro Woche" subtitle="Die letzten 12 Wochen" :icon="CalendarDays">
                        <div class="flex h-36 items-end gap-1">
                            <div v-for="w in stats.weeks" :key="w.week" class="flex flex-1 flex-col items-center gap-1">
                                <span class="text-[10px] font-bold">{{ w.tasks || '' }}</span>
                                <div
                                    class="w-full rounded-t bg-amber-500"
                                    :style="{ height: `${(w.tasks / maxWeek) * 100}px` }"
                                    :title="`ab ${w.starts_on}: ${w.tasks}`"
                                />
                            </div>
                        </div>
                    </GamePanel>

                    <GamePanel title="Lieblings-Wochentage" :icon="Flame">
                        <div class="flex h-36 items-end gap-2">
                            <div v-for="d in stats.weekdays" :key="d.weekday" class="flex flex-1 flex-col items-center gap-1">
                                <div
                                    class="w-full rounded-t bg-emerald-500"
                                    :style="{ height: `${(d.tasks / maxWeekday) * 100}px` }"
                                    :title="`${d.tasks}`"
                                />
                                <span class="text-xs font-bold">{{ weekdayNames[d.weekday - 1] }}</span>
                            </div>
                        </div>
                    </GamePanel>

                    <GamePanel title="Bereiche" subtitle="Letzte 30 Tage">
                        <div v-for="c in stats.categories" :key="c.category" class="flex items-center gap-2 text-sm">
                            <span class="w-32 truncate font-semibold">{{ categories[c.category] ?? 'Sonstiges' }}</span>
                            <div class="h-3 flex-1 overflow-hidden rounded-full bg-muted">
                                <div class="h-full bg-sky-500" :style="{ width: `${(c.tasks / maxCategory) * 100}%` }" />
                            </div>
                            <span class="w-8 text-right tabular-nums">{{ c.tasks }}</span>
                        </div>
                        <p v-if="!stats.categories.length" class="text-sm text-muted-foreground">Noch keine Aufgaben in den letzten 30 Tagen.</p>
                        <p v-if="stats.private_tasks" class="text-xs text-muted-foreground">Dazu {{ stats.private_tasks }} private Aufgaben.</p>
                    </GamePanel>

                    <GamePanel title="Serien und Erfolge" :icon="Trophy">
                        <div v-for="(s, i) in stats.streaks" :key="i" class="flex items-center gap-2 text-sm">
                            <span>🔥</span><span class="flex-1 truncate font-semibold">{{ s.task }}</span
                            ><span class="font-bold">{{ s.streak }} Tage</span>
                        </div>
                        <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                            <div v-for="t in totals" :key="t.key" class="rounded-md bg-background/60 p-2 text-center">
                                <p class="text-lg font-extrabold">{{ t.icon }} {{ stats.totals[t.key] ?? 0 }}</p>
                                <p class="text-[11px] font-semibold text-muted-foreground">{{ t.label }}</p>
                            </div>
                        </div>
                    </GamePanel>
                </div>
            </template>

            <template v-else-if="family">
                <p class="text-sm text-muted-foreground">
                    Gemeinsam statt gegeneinander: Hier seht ihr, was die Familie in den letzten 4 Wochen geschafft hat.
                </p>
                <div class="grid gap-3 sm:grid-cols-4">
                    <div class="hh-panel hh-parchment p-3">
                        <p class="text-2xl font-extrabold">{{ family.totals.tasks_4_weeks }}</p>
                        <p class="text-xs">Aufgaben gesamt</p>
                    </div>
                    <div class="hh-panel hh-parchment p-3">
                        <p class="text-2xl font-extrabold">{{ family.totals.family_tasks_4_weeks }}</p>
                        <p class="text-xs">Familienaufgaben</p>
                    </div>
                    <div class="hh-panel hh-parchment p-3">
                        <p class="text-2xl font-extrabold">{{ family.totals.dungeons_cleared }}</p>
                        <p class="text-xs">Dungeons gemeinsam</p>
                    </div>
                    <div class="hh-panel hh-parchment p-3">
                        <p class="text-2xl font-extrabold">{{ family.totals.items_crafted }}</p>
                        <p class="text-xs">Hergestellt</p>
                    </div>
                </div>
                <GamePanel title="Familie pro Woche" :icon="Users">
                    <div class="flex h-36 items-end gap-3">
                        <div v-for="w in family.weeks" :key="w.week" class="flex flex-1 flex-col items-center gap-1">
                            <span class="text-xs font-bold">{{ w.tasks }}</span>
                            <div class="w-full rounded-t bg-amber-500" :style="{ height: `${(w.tasks / maxFamilyWeek) * 100}px` }" />
                            <span class="text-[10px]">{{
                                new Date(w.starts_on).toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit' })
                            }}</span>
                        </div>
                    </div>
                </GamePanel>
                <GamePanel title="Mitglieder" subtitle="Aufgaben je Woche – private Aufgaben zählen nur als Anzahl mit">
                    <div v-for="m in family.members" :key="m.user_id" class="flex items-center gap-3 text-sm">
                        <button class="w-28 truncate text-left font-bold underline-offset-2 hover:underline" @click="pick(m.user_id)">
                            {{ m.name }}
                        </button>
                        <span class="flex-1 tabular-nums text-muted-foreground">{{ m.weeks.join(' · ') }}</span>
                        <span class="font-bold">{{ m.tasks_4_weeks }}</span>
                    </div>
                </GamePanel>
            </template>
        </div>
    </AppLayout>
</template>
