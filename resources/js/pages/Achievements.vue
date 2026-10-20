<script setup lang="ts">
import AchievementBadge from '@/components/game/AchievementBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { Coins, Trophy } from 'lucide-vue-next';
import { computed } from 'vue';

interface Achievement {
    key: string;
    group: string;
    name: string;
    title: string;
    motif: string;
    tier: number;
    tiers: number;
    value: number;
    next: number | null;
    next_description: string | null;
    progress: number;
    unlocked: { tier: number; title: string; description: string; gold: number; at: string }[];
}

const props = defineProps<{
    member: { id: number; name: string };
    achievements: Achievement[];
    members: { id: number; name: string; avatar_color: string | null; count: number }[];
}>();

const page = usePage<SharedData>();
const isMe = computed(() => props.member.id === page.props.auth.user?.id);

const groups = computed(() => {
    const map = new Map<string, Achievement[]>();
    for (const a of props.achievements) map.set(a.group, [...(map.get(a.group) ?? []), a]);
    return [...map.entries()];
});

const reached = computed(() => props.achievements.reduce((sum, a) => sum + a.tier, 0));
const total = computed(() => props.achievements.reduce((sum, a) => sum + a.tiers, 0));
const date = (iso: string) => new Date(iso).toLocaleDateString('de-DE', { day: 'numeric', month: 'short', year: 'numeric' });
const fmt = (n: number) => n.toLocaleString('de-DE');
</script>

<template>
    <Head title="Erfolge" />

    <AppLayout :breadcrumbs="[{ title: 'Erfolge', href: '/achievements' }]">
        <div class="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h1 class="flex items-center gap-2 text-2xl font-extrabold tracking-tight">
                        <Trophy class="size-6 text-amber-500" /> {{ isMe ? 'Deine Erfolge' : `Erfolge von ${member.name}` }}
                    </h1>
                    <p class="text-sm text-muted-foreground">
                        Abzeichen gibt es fürs Dranbleiben, Helfen und Abenteuer. Jede Stufe bringt etwas Gold – und einmal erreicht, bleibt sie für immer.
                    </p>
                </div>
                <div class="rounded-2xl border bg-card px-4 py-2 text-right">
                    <p class="text-2xl font-extrabold text-amber-500">{{ reached }} <span class="text-base text-muted-foreground">/ {{ total }}</span></p>
                    <p class="text-xs text-muted-foreground">Stufen erreicht</p>
                </div>
            </div>

            <nav v-if="members.length > 1" class="flex flex-wrap gap-2" aria-label="Familienmitglied wählen">
                <Link
                    v-for="m in members"
                    :key="m.id"
                    :href="route('achievements.index', { member: m.id })"
                    preserve-scroll
                    class="flex items-center gap-2 rounded-full border px-3 py-1 text-sm font-semibold transition"
                    :class="m.id === member.id ? 'border-amber-400 bg-amber-50 dark:bg-amber-950/40' : 'hover:bg-muted'"
                >
                    <span class="size-2.5 rounded-full" :style="{ background: m.avatar_color ?? '#94a3b8' }" />
                    {{ m.name }}
                    <span class="rounded-full bg-muted px-1.5 text-xs">{{ m.count }}</span>
                </Link>
            </nav>

            <section v-for="[group, items] in groups" :key="group" class="grid gap-3">
                <h2 class="text-lg font-extrabold">{{ group }}</h2>
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    <article v-for="a in items" :key="a.key" class="flex gap-3 rounded-2xl border bg-card p-4" :class="{ 'border-amber-300 dark:border-amber-700': a.tier === a.tiers }">
                        <AchievementBadge :motif="a.motif" :tier="a.tier" :tiers="a.tiers" :size="64" />
                        <div class="min-w-0 flex-1">
                            <p class="font-extrabold leading-tight">{{ a.title }}</p>
                            <p class="text-xs text-muted-foreground">
                                <template v-if="a.tiers > 1">Stufe {{ a.tier }} von {{ a.tiers }}</template>
                                <template v-else>{{ a.tier ? 'Geschafft' : 'Noch offen' }}</template>
                            </p>

                            <template v-if="a.next !== null">
                                <p class="mt-1.5 text-sm">{{ a.next_description }}</p>
                                <div class="mt-1.5 flex items-center gap-2">
                                    <div class="h-2 flex-1 overflow-hidden rounded-full bg-muted" role="progressbar" :aria-valuenow="a.value" :aria-valuemax="a.next">
                                        <div class="h-full rounded-full bg-gradient-to-r from-amber-400 to-orange-500" :style="{ width: `${a.progress * 100}%` }" />
                                    </div>
                                    <span class="text-xs font-semibold tabular-nums text-muted-foreground">{{ fmt(Math.min(a.value, a.next)) }} / {{ fmt(a.next) }}</span>
                                </div>
                            </template>
                            <p v-else class="mt-1.5 text-sm font-semibold text-amber-600">Alle Stufen geschafft! 🎉</p>

                            <ul v-if="a.unlocked.length" class="mt-2 flex flex-wrap gap-1">
                                <li
                                    v-for="u in a.unlocked"
                                    :key="u.tier"
                                    class="rounded-full bg-muted px-2 py-0.5 text-[11px] font-semibold"
                                    :title="`${u.description} – erreicht am ${date(u.at)}`"
                                >
                                    {{ a.tiers > 1 ? `Stufe ${u.tier}` : 'Erreicht' }} · {{ date(u.at) }}
                                    <span v-if="u.gold" class="inline-flex items-center gap-0.5 text-amber-600"><Coins class="size-3" />{{ u.gold }}</span>
                                </li>
                            </ul>
                        </div>
                    </article>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
