<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    items: { id: number; name: string; description: string | null; type: string; rarity: string; icon: string; quantity: number }[];
    types: Record<string, string>;
    rarities: Record<string, string>;
}>();

const groups = computed(() => {
    const result: Record<string, typeof props.items> = {};
    for (const item of props.items) (result[item.type] ??= []).push(item);
    return result;
});

const rarityRing: Record<string, string> = {
    common: 'ring-slate-200 dark:ring-slate-700',
    uncommon: 'ring-emerald-300',
    rare: 'ring-sky-400',
    epic: 'ring-violet-500',
    legendary: 'ring-amber-400 shadow-amber-200 shadow-md',
};
</script>

<template>
    <Head title="Inventar" />

    <AppLayout :breadcrumbs="[{ title: 'Inventar', href: '/inventory' }]">
        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <h1 class="text-2xl font-extrabold tracking-tight">Inventar</h1>

            <p v-if="!items.length" class="rounded-2xl border border-dashed p-10 text-center text-muted-foreground">
                Noch leer. Erledige Aufgaben – manchmal findest du dabei Eier, Futter oder Materialien!
            </p>

            <section v-for="(list, type) in groups" :key="type" class="grid gap-3">
                <h2 class="font-extrabold">{{ types[type] ?? type }}</h2>
                <div class="grid grid-cols-3 gap-3 sm:grid-cols-5 lg:grid-cols-8">
                    <div
                        v-for="item in list"
                        :key="item.id"
                        class="relative flex flex-col items-center gap-1 rounded-2xl bg-card p-3 text-center ring-2"
                        :class="rarityRing[item.rarity]"
                        :title="`${item.name} (${rarities[item.rarity]})${item.description ? ' – ' + item.description : ''}`"
                    >
                        <span class="text-4xl">{{ item.icon }}</span>
                        <span class="line-clamp-2 text-xs font-semibold">{{ item.name }}</span>
                        <span class="absolute right-1.5 top-1.5 rounded-full bg-foreground px-1.5 text-[11px] font-bold text-background">×{{ item.quantity }}</span>
                    </div>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
