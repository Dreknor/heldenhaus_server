<script setup lang="ts">
import Emoji from '@/components/game/Emoji.vue';
import GamePanel from '@/components/game/GamePanel.vue';
import { router } from '@inertiajs/vue3';
import { CalendarHeart, Share2 } from 'lucide-vue-next';
import { computed } from 'vue';

export interface Review {
    id: number;
    kind: 'week' | 'month' | 'year';
    label: string;
    period_start: string;
    period_end: string;
    seen: boolean;
    shared: boolean;
    can_share: boolean;
    tasks: number;
    active_days: number;
    xp: number;
    message?: string;
    pride?: string;
    highlights?: { icon: string; text: string }[];
    goals?: { title: string; kind: string | null }[];
    goals_supported?: boolean;
    longest_streak?: { days: number; task: string | null } | null;
    best_day?: { date: string; weekday: string; tasks: number } | null;
    perfect_days?: number;
    heatmap?: { date: string; tasks: number; level: number; inn: boolean }[];
    first_weekday?: number;
    pets?: { name: string; icon: string }[];
    badges?: { name: string; icon: string }[];
    quests_won?: number;
    months?: { month: number; label: string; tasks: number }[];
    best_month?: string | null;
    best_streak?: number;
    private_items?: number;
}

const props = withDefaults(defineProps<{ review: Review; compact?: boolean; markable?: boolean }>(), { compact: false, markable: false });

const titles = { week: 'Dein Wochenrückblick', month: 'Deine Monatskarte', year: 'Dein Jahresrückblick' };
const heat = ['bg-muted', 'bg-emerald-200 dark:bg-emerald-900', 'bg-emerald-300 dark:bg-emerald-700', 'bg-emerald-500', 'bg-emerald-600'];
const offset = computed(() => Array.from({ length: Math.max(0, (props.review.first_weekday ?? 1) - 1) }));
const maxMonth = computed(() => Math.max(1, ...(props.review.months ?? []).map((m) => m.tasks)));

function seen() {
    router.post(route('reviews.seen', { review: props.review.id }), {}, { preserveScroll: true });
}

function share() {
    router.post(route('reviews.share', { review: props.review.id }), {}, { preserveScroll: true });
}
</script>

<template>
    <GamePanel tone="sky" :title="titles[review.kind]" :subtitle="review.label" :icon="CalendarHeart">
        <template v-if="review.kind === 'week'">
            <p class="text-lg font-extrabold">{{ review.message }}</p>
            <ul v-if="review.highlights?.length" class="grid gap-1.5">
                <li
                    v-for="(h, i) in review.highlights"
                    :key="i"
                    class="flex items-center gap-2 rounded-md bg-white/50 px-2.5 py-1.5 text-sm font-semibold dark:bg-black/20"
                >
                    <span class="text-xl"><Emoji :glyph="h.icon" /></span> {{ h.text }}
                </li>
            </ul>
            <div v-if="!compact" class="flex flex-wrap gap-2 text-sm">
                <span class="rounded-full bg-white/60 px-3 py-1 font-bold dark:bg-black/20">✅ {{ review.tasks }} Aufgaben</span>
                <span v-if="review.best_day" class="rounded-full bg-white/60 px-3 py-1 font-bold dark:bg-black/20">
                    💪 Stärkster Tag: {{ review.best_day.weekday }} ({{ review.best_day.tasks }})
                </span>
                <span v-if="review.longest_streak" class="rounded-full bg-white/60 px-3 py-1 font-bold dark:bg-black/20">
                    🔥 Längste Serie: {{ review.longest_streak.days }} Tage<template v-if="review.longest_streak.task">
                        · {{ review.longest_streak.task }}</template
                    >
                </span>
            </div>
            <div v-if="!compact && review.goals?.length" class="grid gap-1">
                <p class="text-xs font-bold uppercase text-[var(--panel-sub)]">Erreichte Ziele</p>
                <p v-for="(g, i) in review.goals" :key="i" class="text-sm font-semibold">🎯 {{ g.title }}</p>
            </div>
        </template>

        <template v-else-if="review.kind === 'month'">
            <p class="text-lg font-extrabold">{{ review.pride }}</p>
            <div class="grid max-w-xs grid-cols-7 gap-1" aria-label="Kalender deiner Aktivität">
                <span v-for="(_, i) in offset" :key="`o${i}`" />
                <span
                    v-for="d in review.heatmap"
                    :key="d.date"
                    class="aspect-square rounded-sm border border-black/10"
                    :class="d.inn ? 'bg-sky-200 dark:bg-sky-800' : heat[d.level]"
                    :title="`${new Date(d.date).toLocaleDateString('de-DE')}: ${d.inn ? 'Gasthaus' : `${d.tasks} Aufgaben`}`"
                />
            </div>
            <div class="flex flex-wrap gap-2 text-sm">
                <span class="rounded-full bg-white/60 px-3 py-1 font-bold dark:bg-black/20">✅ {{ review.tasks }} Aufgaben</span>
                <span class="rounded-full bg-white/60 px-3 py-1 font-bold dark:bg-black/20">📅 {{ review.active_days }} aktive Tage</span>
                <span v-for="(p, i) in review.pets" :key="`p${i}`" class="rounded-full bg-white/60 px-3 py-1 font-bold dark:bg-black/20">
                    <Emoji :glyph="p.icon" /> {{ p.name }}
                </span>
                <span v-for="(b, i) in review.badges" :key="`b${i}`" class="rounded-full bg-white/60 px-3 py-1 font-bold dark:bg-black/20">
                    <Emoji :glyph="b.icon" /> {{ b.name }}
                </span>
            </div>
        </template>

        <template v-else>
            <p class="text-lg font-extrabold">{{ review.pride }}</p>
            <div class="flex h-24 items-end gap-1" aria-label="Aufgaben je Monat">
                <div v-for="m in review.months" :key="m.month" class="flex flex-1 flex-col items-center gap-1">
                    <div
                        class="w-full rounded-t bg-emerald-500"
                        :style="{ height: `${(m.tasks / maxMonth) * 72}px` }"
                        :title="`${m.label}: ${m.tasks}`"
                    />
                    <span class="text-[10px] font-bold">{{ m.label.slice(0, 3) }}</span>
                </div>
            </div>
            <div class="flex flex-wrap gap-2 text-sm">
                <span class="rounded-full bg-white/60 px-3 py-1 font-bold dark:bg-black/20">✅ {{ review.tasks }} Aufgaben</span>
                <span v-if="review.best_month" class="rounded-full bg-white/60 px-3 py-1 font-bold dark:bg-black/20"
                    >🌟 Bester Monat: {{ review.best_month }}</span
                >
                <span class="rounded-full bg-white/60 px-3 py-1 font-bold dark:bg-black/20">🐾 {{ review.pets?.length ?? 0 }} Haustiere</span>
                <span class="rounded-full bg-white/60 px-3 py-1 font-bold dark:bg-black/20">🏅 {{ review.badges?.length ?? 0 }} Abzeichen</span>
            </div>
        </template>

        <p v-if="review.private_items" class="text-xs font-semibold text-[var(--panel-sub)]">Dazu {{ review.private_items }} private Einträge.</p>

        <div v-if="markable || review.can_share" class="flex flex-wrap gap-2">
            <button
                v-if="review.can_share"
                class="hh-button border-[var(--wood-edge)] bg-[var(--gold)] text-[var(--wood-edge)] hover:brightness-110"
                @click="share"
            >
                <Share2 class="size-4" /> In den Familien-Feed
            </button>
            <button v-if="markable && !review.seen" class="hh-button border-[#2f5c6e] bg-white/70 text-[#123543] hover:brightness-105" @click="seen">
                Super, gelesen!
            </button>
        </div>
    </GamePanel>
</template>
