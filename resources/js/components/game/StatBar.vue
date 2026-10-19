<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    label: string;
    value: number;
    max: number;
    color: 'hp' | 'xp' | 'mp';
}>();

const percent = computed(() => Math.max(0, Math.min(100, (props.value / Math.max(1, props.max)) * 100)));

const colors = {
    hp: 'from-rose-500 to-red-500',
    xp: 'from-amber-400 to-yellow-400',
    mp: 'from-sky-500 to-indigo-500',
};
</script>

<template>
    <div>
        <div class="mb-0.5 flex justify-between text-[11px] font-semibold text-muted-foreground">
            <span>{{ label }}</span>
            <span class="tabular-nums">{{ Math.floor(value) }} / {{ Math.floor(max) }}</span>
        </div>
        <div class="h-2.5 overflow-hidden rounded-full bg-muted">
            <div class="h-full rounded-full bg-gradient-to-r transition-all duration-700 ease-out" :class="colors[color]" :style="{ width: `${percent}%` }" />
        </div>
    </div>
</template>
