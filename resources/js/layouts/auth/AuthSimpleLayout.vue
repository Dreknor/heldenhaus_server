<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import YardBanner from '@/components/game/YardBanner.vue';
import { Link } from '@inertiajs/vue3';

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div
        class="flex min-h-svh flex-col items-center justify-center gap-6 bg-gradient-to-b from-amber-50 via-orange-50 to-rose-50 p-6 dark:from-slate-950 dark:via-slate-900 dark:to-slate-950 md:p-10"
    >
        <div class="w-full max-w-md">
            <YardBanner class="mb-4 min-h-44" :hero-size="96" :pets="false" />
            <div class="relative flex flex-col gap-8 rounded-3xl border bg-card p-6 shadow-lg sm:p-8">
                <div class="flex flex-col items-center gap-4">
                    <Link :href="route('home')" class="flex flex-col items-center gap-2 font-medium">
                        <div class="mb-1 flex h-9 w-9 items-center justify-center rounded-md">
                            <span
                                class="flex size-11 items-center justify-center rounded-xl bg-gradient-to-br from-amber-400 to-orange-500 text-white shadow"
                            >
                                <AppLogoIcon class="size-6 fill-current" />
                            </span>
                        </div>
                        <span class="sr-only">{{ title }}</span>
                    </Link>
                    <div class="space-y-2 text-center">
                        <h1 class="text-2xl font-extrabold">{{ title }}</h1>
                        <p class="text-center text-sm text-muted-foreground">{{ description }}</p>
                    </div>
                </div>
                <slot />
            </div>
        </div>
    </div>
</template>
