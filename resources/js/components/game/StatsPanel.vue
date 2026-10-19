<script setup lang="ts">
import StatBar from '@/components/game/StatBar.vue';
import type { Stats } from '@/types';
import { Coins, Moon, Shield, Star } from 'lucide-vue-next';

defineProps<{ stats: Stats }>();
</script>

<template>
    <div class="space-y-2 rounded-xl border bg-card p-3 text-card-foreground">
        <div class="flex items-center justify-between">
            <span class="rounded-md bg-gradient-to-r from-amber-400 to-orange-500 px-2 py-0.5 text-xs font-extrabold text-white">Level {{ stats.level }}</span>
            <span v-if="stats.sleeping" class="flex items-center gap-1 text-xs text-muted-foreground" title="Gasthaus-Modus: kein Schaden">
                <Moon class="size-3.5" /> Gasthaus
            </span>
        </div>
        <StatBar label="Leben" :value="stats.hp" :max="stats.max_hp" color="hp" />
        <StatBar label="Erfahrung" :value="stats.xp" :max="stats.xp_to_next" color="xp" />
        <StatBar label="Mana" :value="stats.mp" :max="stats.max_mp" color="mp" />
        <div class="flex items-center gap-3 pt-1 text-sm font-bold">
            <span class="flex items-center gap-1 text-amber-600 dark:text-amber-400" title="Gold – für die Spielwelt"><Coins class="size-4" />{{ stats.gold.toFixed(0) }}</span>
            <span class="flex items-center gap-1 text-violet-600 dark:text-violet-400" title="Sterne – für echte Belohnungen"><Star class="size-4 fill-current" />{{ stats.stars }}</span>
            <span v-if="stats.streak_shields" class="flex items-center gap-1 text-sky-600" title="Streak-Schutz"><Shield class="size-4" />{{ stats.streak_shields }}</span>
        </div>
    </div>
</template>
