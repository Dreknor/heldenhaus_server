<script setup lang="ts">
import { cn } from '@/lib/utils';
import type { HTMLAttributes } from 'vue';

const props = defineProps<{
    class?: HTMLAttributes['class'];
}>();
</script>

<template>
    <main
        :class="
            cn(
                'hh-parchment relative flex min-h-svh flex-1 flex-col bg-background text-foreground [background-color:hsl(var(--background))]',
                'peer-data-[variant=inset]:min-h-[calc(100svh-theme(spacing.4))] md:peer-data-[variant=inset]:m-2 md:peer-data-[state=collapsed]:peer-data-[variant=inset]:ml-2 md:peer-data-[variant=inset]:ml-0 md:peer-data-[variant=inset]:rounded-lg md:peer-data-[variant=inset]:border-4 md:peer-data-[variant=inset]:border-[var(--wood-edge)] md:peer-data-[variant=inset]:shadow-[inset_0_0_0_2px_var(--parchment-edge),0_6px_0_rgb(0_0_0/0.4)]',
                props.class,
            )
        "
    >
        <slot />
    </main>
</template>
