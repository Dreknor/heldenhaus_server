<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronRight } from 'lucide-vue-next';
import type { Component } from 'vue';

withDefaults(
    defineProps<{
        title?: string;
        subtitle?: string;
        icon?: Component;
        /** parchment: Pergament · wood: dunkles Holz · meadow: Wiese · sky: Himmelblau */
        tone?: 'parchment' | 'wood' | 'meadow' | 'sky';
        href?: string;
        linkLabel?: string;
    }>(),
    { tone: 'parchment', linkLabel: 'Öffnen' },
);

const tones = {
    parchment: 'hh-parchment',
    wood: 'hh-wood text-[#f3e3c3] [--panel-sub:#d7bf98]',
    meadow: 'bg-[#8cc152] text-[#1f3510] [--panel-sub:#2e4d17] dark:bg-[#3f6a24] dark:text-[#eaf6d8] dark:[--panel-sub:#cfe6b3]',
    sky: 'bg-[#cfeaf3] text-[#123543] [--panel-sub:#2f5c6e] dark:bg-[#1f4352] dark:text-[#dff3fa] dark:[--panel-sub:#a9d3e2]',
};
</script>

<template>
    <section class="hh-panel flex flex-col gap-3 p-3 sm:p-4" :class="tones[tone]">
        <header v-if="title || $slots.action" class="flex items-start gap-2">
            <component :is="icon" v-if="icon" class="mt-0.5 size-5 shrink-0 opacity-80" />
            <div class="min-w-0 flex-1">
                <h2 v-if="title" class="hh-panel-title">{{ title }}</h2>
                <p v-if="subtitle" class="text-xs font-semibold" :class="tone === 'parchment' ? 'text-muted-foreground' : 'text-[var(--panel-sub)]'">
                    {{ subtitle }}
                </p>
            </div>
            <slot name="action">
                <Link
                    v-if="href"
                    :href="href"
                    class="hh-button shrink-0 border-[var(--wood-edge)] bg-[var(--gold)] px-2 py-0.5 text-xs text-[var(--wood-edge)] hover:brightness-110"
                >
                    {{ linkLabel }} <ChevronRight class="size-3.5" />
                </Link>
            </slot>
        </header>
        <slot />
    </section>
</template>
