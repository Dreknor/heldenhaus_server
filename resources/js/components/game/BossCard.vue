<script setup lang="ts">
import StatBar from '@/components/game/StatBar.vue';
import { computed } from 'vue';

export interface Boss {
    id: number;
    name: string;
    icon: string;
    hp: number;
    max_hp: number;
    reward_text: string | null;
    status: string;
    ends_at: string;
    contributions: { name: string; avatar_color: string; damage: number }[];
}

const props = defineProps<{ boss: Boss }>();

const daysLeft = computed(() => Math.max(0, Math.ceil((new Date(props.boss.ends_at).getTime() - Date.now()) / 86_400_000)));
</script>

<template>
    <section class="flex flex-col gap-3 rounded-2xl border-2 border-rose-200 bg-gradient-to-br from-rose-50 to-orange-50 p-4 sm:flex-row sm:items-center dark:border-rose-900 dark:from-rose-950/30 dark:to-orange-950/20">
        <span class="text-6xl leading-none sm:text-7xl" aria-hidden="true">{{ boss.icon }}</span>
        <div class="min-w-0 flex-1 space-y-2">
            <div class="flex flex-wrap items-baseline gap-x-3">
                <h2 class="text-lg font-extrabold">Familien-Boss: {{ boss.name }}</h2>
                <span class="text-xs font-semibold text-muted-foreground">noch {{ daysLeft }} {{ daysLeft === 1 ? 'Tag' : 'Tage' }}</span>
            </div>
            <StatBar label="Boss-Leben" :value="boss.hp" :max="boss.max_hp" color="hp" />
            <p v-if="boss.reward_text" class="text-sm">🎁 Wenn ihr gewinnt: <strong>{{ boss.reward_text }}</strong></p>
            <div v-if="boss.contributions.length" class="flex flex-wrap gap-2 text-xs">
                <span v-for="c in boss.contributions" :key="c.name" class="flex items-center gap-1 rounded-full bg-white/70 px-2 py-0.5 font-semibold dark:bg-white/10">
                    <span class="size-2.5 rounded-full" :style="{ background: c.avatar_color }" /> {{ c.name }}: {{ c.damage }}
                </span>
            </div>
        </div>
    </section>
</template>
