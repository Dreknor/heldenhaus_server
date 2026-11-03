<script setup lang="ts">
import { cn } from '@/lib/utils';
import type { HTMLAttributes } from 'vue';

const props = defineProps<{
    class?: HTMLAttributes['class'];
}>();
</script>

<template>
    <h3 :class="cn('font-pixel text-2xl font-semibold leading-none tracking-wide', props.class)">
        <slot />
    </h3>
</template>
