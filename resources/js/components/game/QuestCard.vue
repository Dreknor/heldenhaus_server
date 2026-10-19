<script setup lang="ts">
import StatBar from '@/components/game/StatBar.vue';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

export interface ActiveQuest {
    id: number;
    scope: 'family' | 'solo';
    type: 'boss' | 'collect';
    key: string;
    name: string;
    icon: string;
    description: string | null;
    goal: number;
    progress: number;
    remaining: number;
    weakness: string | null;
    resist: string | null;
    rage: number | null;
    categories: string[];
    item_name: string | null;
    item_icon: string | null;
    rewards: { gold?: number; xp?: number; item?: string } | null;
    reward_text: string | null;
    status: string;
    ends_at: string;
    contributions: { name: string; avatar_color: string; amount: number }[];
}

const props = defineProps<{ quest: ActiveQuest; compact?: boolean }>();

const daysLeft = computed(() => Math.max(0, Math.ceil((new Date(props.quest.ends_at).getTime() - Date.now()) / 86_400_000)));
const isBoss = computed(() => props.quest.type === 'boss');
const theme = computed(() =>
    isBoss.value
        ? 'border-rose-200 from-rose-50 to-orange-50 dark:border-rose-900 dark:from-rose-950/30 dark:to-orange-950/20'
        : 'border-emerald-200 from-emerald-50 to-sky-50 dark:border-emerald-900 dark:from-emerald-950/30 dark:to-sky-950/20',
);
</script>

<template>
    <section class="flex flex-col gap-3 rounded-2xl border-2 bg-gradient-to-br p-4 sm:flex-row sm:items-center" :class="theme">
        <span class="text-6xl leading-none" aria-hidden="true">{{ quest.icon }}</span>
        <div class="min-w-0 flex-1 space-y-2">
            <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                <span class="rounded-full px-2 py-0.5 text-[11px] font-bold uppercase" :class="quest.scope === 'family' ? 'bg-violet-600 text-white' : 'bg-sky-600 text-white'">
                    {{ quest.scope === 'family' ? 'Familien-Quest' : 'Solo-Quest' }}
                </span>
                <h2 class="text-lg font-extrabold">{{ quest.name }}</h2>
                <span class="text-xs font-semibold text-muted-foreground">noch {{ daysLeft }} {{ daysLeft === 1 ? 'Tag' : 'Tage' }}</span>
            </div>
            <StatBar v-if="isBoss" label="Boss-Leben" :value="quest.remaining" :max="quest.goal" color="hp" />
            <StatBar v-else :label="`${quest.item_icon} ${quest.item_name} gesammelt`" :value="quest.progress" :max="quest.goal" color="xp" />
            <div v-if="!compact" class="flex flex-wrap gap-1.5 text-xs font-semibold">
                <span v-if="quest.weakness" class="rounded-full bg-white/70 px-2 py-0.5 dark:bg-white/10">💥 Schwach gegen {{ quest.weakness }}</span>
                <span v-if="quest.resist" class="rounded-full bg-white/70 px-2 py-0.5 dark:bg-white/10">🛡️ Widersteht {{ quest.resist }}</span>
                <span v-if="quest.rage" class="rounded-full bg-white/70 px-2 py-0.5 dark:bg-white/10">😠 Wut: heilt {{ quest.rage }}× bei verpassten Dailies</span>
                <span v-if="quest.categories.length" class="rounded-full bg-white/70 px-2 py-0.5 dark:bg-white/10">Zählt bei: {{ quest.categories.join(', ') }}</span>
            </div>
            <p v-if="quest.reward_text" class="text-sm">🎁 Wenn ihr es schafft: <strong>{{ quest.reward_text }}</strong></p>
            <div v-if="quest.contributions.length && !compact" class="flex flex-wrap gap-2 text-xs">
                <span v-for="c in quest.contributions" :key="c.name" class="flex items-center gap-1 rounded-full bg-white/70 px-2 py-0.5 font-semibold dark:bg-white/10">
                    <span class="size-2.5 rounded-full" :style="{ background: c.avatar_color }" /> {{ c.name }}: {{ c.amount }}
                </span>
            </div>
        </div>
        <Link v-if="compact" :href="route('quests.index')" class="self-start text-sm font-semibold underline sm:self-center">Details</Link>
    </section>
</template>
