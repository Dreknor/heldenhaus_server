<script setup lang="ts">
import Emoji from '@/components/game/Emoji.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';
import { CalendarHeart, Check, Coins, Lock } from 'lucide-vue-next';
import { computed } from 'vue';

interface Stage {
    level: number;
    reached: boolean;
    gold: number;
    item: { key: string; name: string; icon: string; type: string } | null;
    badge: boolean;
}

const props = defineProps<{
    year: {
        label: string;
        starts_on: string;
        ends_on: string;
        restart_on: string;
        days_left: number;
        year_progress: number;
        points: number;
        level: number;
        max_level: number;
        points_per_level: number;
        level_points: number;
        points_today: number;
        daily_task_cap: number;
        task_points: Record<string, number>;
        stages: Stage[];
        history: { label: string; level: number; points: number }[];
    };
}>();

const date = (iso: string) => new Date(`${iso}T12:00:00`).toLocaleDateString('de-DE', { day: 'numeric', month: 'long', year: 'numeric' });
const levelPercent = computed(() => Math.min(100, (props.year.level_points / props.year.points_per_level) * 100));
// Besondere Stufen hervorheben, die übrigen geben „nur“ Gold
const special = computed(() => props.year.stages.filter((s) => s.item || s.badge));
const next = computed(() => props.year.stages.find((s) => !s.reached && (s.item || s.badge)) ?? null);
const done = computed(() => props.year.level >= props.year.max_level);
</script>

<template>
    <Head title="Heldenjahr" />

    <AppLayout :breadcrumbs="[{ title: 'Heldenjahr', href: '/hero-year' }]">
        <div class="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
            <div>
                <h1 class="flex items-center gap-2 text-2xl font-extrabold tracking-tight">
                    <CalendarHeart class="size-6 text-rose-500" /> Heldenjahr {{ year.label }}
                </h1>
                <p class="text-sm text-muted-foreground">
                    50 Stufen für das ganze Schuljahr – kostenlos. Neustart am {{ date(year.restart_on) }}, nach den Sommerferien.
                </p>
            </div>

            <section class="hh-panel hh-parchment grid gap-4 p-4 md:grid-cols-[auto_1fr]">
                <div class="flex flex-col items-center justify-center rounded-2xl bg-card px-6 py-3 text-center shadow-sm">
                    <p class="text-xs font-bold uppercase text-muted-foreground">Stufe</p>
                    <p class="font-pixel text-5xl text-amber-600">{{ year.level }}</p>
                    <p class="text-xs text-muted-foreground">von {{ year.max_level }}</p>
                </div>
                <div class="flex flex-col justify-center gap-2">
                    <template v-if="!done">
                        <p class="font-bold">Noch {{ year.points_per_level - year.level_points }} Punkte bis Stufe {{ year.level + 1 }}</p>
                        <div
                            class="h-4 overflow-hidden rounded-full border bg-muted"
                            role="progressbar"
                            :aria-valuenow="year.level_points"
                            :aria-valuemax="year.points_per_level"
                        >
                            <div class="h-full rounded-full bg-gradient-to-r from-rose-400 to-amber-400" :style="{ width: `${levelPercent}%` }" />
                        </div>
                    </template>
                    <p v-else class="text-lg font-extrabold text-amber-600">Alle 50 Stufen geschafft – du bist ein Held des Jahres! 🎉</p>
                    <p class="text-sm text-muted-foreground">
                        Heute {{ year.points_today }} von {{ year.daily_task_cap }} Punkten aus Aufgaben · {{ year.points }} Punkte im Jahr · noch
                        {{ year.days_left }} Tage
                    </p>
                    <p class="text-xs text-muted-foreground">
                        Punkte gibt es für jede Aufgabe ({{ year.task_points.easy }} für leicht, {{ year.task_points.hard }} für schwer), für Events
                        und Ziele.
                    </p>
                    <p v-if="next" class="text-sm font-semibold">
                        Nächste große Belohnung bei Stufe {{ next.level }}:
                        <template v-if="next.item"><Emoji :glyph="next.item.icon" /> {{ next.item.name }}</template>
                        <template v-if="next.badge"> + Jahres-Abzeichen 🏅</template>
                    </p>
                </div>
            </section>

            <section class="grid gap-3">
                <h2 class="text-lg font-extrabold">Die Jahresleiste</h2>
                <ol class="grid grid-cols-5 gap-1.5 sm:grid-cols-10" aria-label="Stufen">
                    <li
                        v-for="s in year.stages"
                        :key="s.level"
                        class="relative flex aspect-square flex-col items-center justify-center rounded-lg border text-center"
                        :class="[
                            s.reached ? 'border-amber-400 bg-amber-100 dark:bg-amber-900/40' : 'bg-card',
                            s.item || s.badge ? 'border-2' : '',
                            s.level === year.level + 1 ? 'ring-2 ring-rose-400' : '',
                        ]"
                        :title="`Stufe ${s.level}: ${s.gold} Gold${s.item ? ` + ${s.item.name}` : ''}${s.badge ? ' + Jahres-Abzeichen' : ''}`"
                    >
                        <span class="text-[10px] font-bold text-muted-foreground">{{ s.level }}</span>
                        <span v-if="s.item || s.badge" class="text-lg"><Emoji :glyph="s.badge ? '🏅' : s.item?.icon" /></span>
                        <Coins v-else class="size-3.5 text-amber-500" />
                        <Check v-if="s.reached" class="absolute right-0.5 top-0.5 size-3 text-amber-700" aria-label="erreicht" />
                    </li>
                </ol>
            </section>

            <section class="grid gap-3">
                <h2 class="text-lg font-extrabold">Besondere Belohnungen</h2>
                <ul class="grid gap-2 sm:grid-cols-2 xl:grid-cols-5">
                    <li
                        v-for="s in special"
                        :key="s.level"
                        class="flex items-center gap-3 rounded-2xl border bg-card p-3"
                        :class="{ 'border-amber-400': s.reached }"
                    >
                        <span class="text-3xl"><Emoji :glyph="s.badge ? '🏅' : s.item?.icon" /></span>
                        <div class="min-w-0 flex-1">
                            <p class="text-xs font-bold text-muted-foreground">Stufe {{ s.level }}</p>
                            <p class="font-bold leading-tight">{{ s.badge ? 'Jahres-Abzeichen' : s.item?.name }}</p>
                            <p v-if="s.badge && s.item" class="text-xs">+ {{ s.item.name }}</p>
                        </div>
                        <Check v-if="s.reached" class="size-5 text-amber-600" aria-label="erhalten" />
                        <Lock v-else class="size-4 text-muted-foreground" aria-label="noch offen" />
                    </li>
                </ul>
            </section>

            <section v-if="year.history.length" class="grid gap-2">
                <h2 class="text-lg font-extrabold">Frühere Heldenjahre</h2>
                <ul class="flex flex-wrap gap-2">
                    <li v-for="h in year.history" :key="h.label" class="rounded-full border bg-card px-3 py-1 text-sm font-semibold">
                        {{ h.label }} · Stufe {{ h.level }}
                    </li>
                </ul>
            </section>
        </div>
    </AppLayout>
</template>
