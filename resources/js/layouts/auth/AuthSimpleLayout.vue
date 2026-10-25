<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Link } from '@inertiajs/vue3';

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div class="hh-wood flex min-h-svh flex-col items-center justify-center gap-6 p-4 md:p-10">
        <div class="hh-panel hh-parchment w-full max-w-sm p-6 sm:p-8">
            <div class="flex flex-col gap-8">
                <div class="flex flex-col items-center gap-4">
                    <Link :href="route('home')" class="flex flex-col items-center gap-2 font-medium">
                        <div
                            class="mb-1 flex size-12 items-center justify-center rounded-md border-2 border-[var(--wood-edge)] bg-gradient-to-br from-amber-400 to-orange-500 text-white shadow-[0_3px_0_rgb(0_0_0/0.4)]"
                        >
                            <AppLogoIcon class="size-8 fill-current" />
                        </div>
                        <span class="font-pixel text-2xl font-bold tracking-wide text-[#8a4b0c] dark:text-[var(--gold)]">Heldenhaus</span>
                        <span class="sr-only">{{ title }}</span>
                    </Link>
                    <div class="space-y-2 text-center">
                        <h1 class="font-pixel text-xl tracking-wide">{{ title }}</h1>
                        <p class="text-center text-sm text-muted-foreground">{{ description }}</p>
                    </div>
                </div>
                <slot />
            </div>
        </div>
    </div>
</template>
