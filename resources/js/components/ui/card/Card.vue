<script setup lang="ts">
import { cn } from '@/lib/utils';
import type { HTMLAttributes } from 'vue';

const props = defineProps<{
    class?: HTMLAttributes['class'];
}>();
</script>

<template>
    <div :class="cn('rounded-lg border-2 border-[var(--parchment-edge)] bg-card text-card-foreground shadow-[0_3px_0_rgb(80_50_20/0.18)]', props.class)">
        <slot />
    </div>
</template>
