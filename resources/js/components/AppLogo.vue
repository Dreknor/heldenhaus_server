<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
</script>

<template>
    <div
        class="flex aspect-square size-8 items-center justify-center rounded-md border-2 border-[var(--wood-edge)] bg-gradient-to-br from-amber-400 to-orange-500 text-white shadow-[0_2px_0_rgb(0_0_0/0.4)]"
    >
        <AppLogoIcon class="size-5 fill-current" />
    </div>
    <div class="ml-1 grid flex-1 text-left">
        <span class="truncate font-pixel text-xl font-bold leading-none tracking-wide text-[var(--gold)] [text-shadow:0_2px_0_rgb(0_0_0/0.5)]"
            >Heldenhaus</span
        >
    </div>
</template>
