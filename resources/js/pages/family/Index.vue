<script setup lang="ts">
import FeedList, { type FeedEntryView } from '@/components/game/FeedList.vue';
import HeroAvatar from '@/components/game/HeroAvatar.vue';
import QuestCard, { type ActiveQuest } from '@/components/game/QuestCard.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import type { Member } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { Check, MessageCircle } from 'lucide-vue-next';

defineProps<{
    family: { name: string };
    heroes: (Member & { done_today: number })[];
    quest: ActiveQuest | null;
    feed: FeedEntryView[];
}>();
</script>

<template>
    <Head title="Familie" />

    <AppLayout :breadcrumbs="[{ title: 'Familie', href: '/family' }]">
        <div class="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="flex-1 text-2xl font-extrabold tracking-tight">{{ family.name }}</h1>
                <Button variant="outline" as-child>
                    <Link :href="route('social.index')"><MessageCircle class="size-4" /> Familienchat</Link>
                </Button>
            </div>

            <!-- Heldengalerie: wer gehört dazu und wie läuft es heute (nur Anzahl, kein Vergleich nach Rang) -->
            <section class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                <article
                    v-for="m in heroes"
                    :key="m.id"
                    class="relative flex flex-col items-center overflow-hidden rounded-3xl border-2 bg-card px-3 pb-4 pt-2 text-center shadow-sm"
                >
                    <div
                        class="absolute inset-x-0 top-0 h-24 bg-gradient-to-b from-sky-200 to-emerald-100 dark:from-slate-800 dark:to-emerald-950/40"
                        aria-hidden="true"
                    />
                    <div class="absolute inset-x-0 top-[5.5rem] h-3 bg-[#8bcf5a] dark:bg-[#2f5d3a]" aria-hidden="true" />
                    <HeroAvatar
                        v-if="m.appearance"
                        :avatar="m.appearance.avatar"
                        :equipment="m.appearance.equipment"
                        :seed="m.appearance.seed"
                        :class-key="m.appearance.class_key"
                        :size="104"
                        :backdrop="false"
                        :mood="m.done_today ? 'happy' : 'normal'"
                        class="relative -mb-1 drop-shadow-md"
                    />
                    <p class="relative mt-1 text-lg font-extrabold leading-tight">{{ m.name }}</p>
                    <p class="relative text-xs font-semibold text-muted-foreground">{{ m.role_label }}</p>
                    <span v-if="m.stats" class="relative mt-1.5 rounded-full bg-amber-500 px-2.5 py-0.5 text-xs font-black text-white shadow-sm"
                        >Level {{ m.stats.level }}</span
                    >
                    <div v-if="m.stats" class="relative mt-2 grid w-full gap-1" aria-hidden="true">
                        <span class="h-1.5 overflow-hidden rounded-full bg-muted">
                            <span class="block h-full rounded-full bg-rose-500" :style="{ width: `${(m.stats.hp / m.stats.max_hp) * 100}%` }" />
                        </span>
                        <span class="h-1.5 overflow-hidden rounded-full bg-muted">
                            <span
                                class="block h-full rounded-full bg-amber-400"
                                :style="{ width: `${(m.stats.xp / Math.max(1, m.stats.xp_to_next)) * 100}%` }"
                            />
                        </span>
                    </div>
                    <p
                        class="relative mt-2 flex items-center gap-1 text-xs font-bold"
                        :class="m.done_today ? 'text-emerald-700 dark:text-emerald-300' : 'text-muted-foreground'"
                    >
                        <Check v-if="m.done_today" class="size-3.5 stroke-[3]" />
                        {{ m.done_today ? `heute ${m.done_today}× geschafft` : 'heute noch nichts – alles gut' }}
                    </p>
                </article>
            </section>

            <QuestCard v-if="quest" :quest="quest" />

            <section class="grid gap-3">
                <h2 class="text-lg font-extrabold">Was in der Familie passiert</h2>
                <FeedList :entries="feed" />
            </section>
        </div>
    </AppLayout>
</template>
