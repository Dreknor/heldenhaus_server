<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { Check, Gift, PartyPopper, Star, Wifi, X } from 'lucide-vue-next';

defineProps<{
    completions: { id: number; task: string; user: string; avatar_color: string; stars: number; source: string; created_at: string }[];
    redemptions: { id: number; reward: string; user: string; avatar_color: string; stars: number; created_at: string }[];
}>();

function review(kind: 'completions' | 'redemptions', id: number, decision: 'approve' | 'reject') {
    let comment: string | null = null;
    if (decision === 'reject') {
        comment = prompt('Kurzer Hinweis für dein Kind (optional):');
        if (comment === null) return;
    }
    const name = kind === 'completions' ? 'completions.review' : 'redemptions.review';
    const param = kind === 'completions' ? { completion: id, decision } : { redemption: id, decision };
    router.post(route(name, param), { comment }, { preserveScroll: true });
}
</script>

<template>
    <Head title="Freigaben" />

    <AppLayout :breadcrumbs="[{ title: 'Freigaben', href: '/approvals' }]">
        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <h1 class="text-2xl font-extrabold tracking-tight">Freigaben</h1>

            <div v-if="!completions.length && !redemptions.length" class="flex flex-col items-center gap-3 rounded-2xl border border-dashed p-12 text-center text-muted-foreground">
                <PartyPopper class="size-10" />
                Alles erledigt – nichts wartet auf dich.
            </div>

            <section v-if="completions.length" class="grid gap-2">
                <h2 class="font-extrabold">Erledigte Aufgaben</h2>
                <div v-for="c in completions" :key="c.id" class="flex flex-wrap items-center gap-3 rounded-xl border bg-card p-3 shadow-sm">
                    <span class="flex size-9 items-center justify-center rounded-xl font-bold text-white" :style="{ background: c.avatar_color }">{{ c.user.charAt(0) }}</span>
                    <div class="min-w-0 flex-1">
                        <p class="font-semibold"><span class="font-bold">{{ c.user }}</span> hat „{{ c.task }}“ erledigt</p>
                        <p class="flex items-center gap-2 text-xs text-muted-foreground">
                            {{ c.created_at }}
                            <span v-if="c.stars" class="flex items-center gap-0.5 text-amber-600"><Star class="size-3 fill-current" /> {{ c.stars }}</span>
                            <span v-if="c.source === 'ha'" class="flex items-center gap-0.5"><Wifi class="size-3" /> Home Assistant</span>
                        </p>
                    </div>
                    <button class="flex items-center gap-1 rounded-lg border px-3 py-2 text-sm font-semibold hover:bg-muted" @click="review('completions', c.id, 'reject')">
                        <X class="size-4" /> Nochmal
                    </button>
                    <button class="flex items-center gap-1 rounded-lg bg-emerald-600 px-3 py-2 text-sm font-bold text-white hover:bg-emerald-700" @click="review('completions', c.id, 'approve')">
                        <Check class="size-4" /> Super!
                    </button>
                </div>
            </section>

            <section v-if="redemptions.length" class="grid gap-2">
                <h2 class="font-extrabold">Eingelöste Belohnungen</h2>
                <div v-for="r in redemptions" :key="r.id" class="flex flex-wrap items-center gap-3 rounded-xl border bg-card p-3 shadow-sm">
                    <Gift class="size-6 text-violet-500" />
                    <div class="min-w-0 flex-1">
                        <p class="font-semibold"><span class="font-bold">{{ r.user }}</span> möchte „{{ r.reward }}“</p>
                        <p class="flex items-center gap-1 text-xs text-muted-foreground">{{ r.created_at }} · <Star class="size-3 fill-current text-amber-500" /> {{ r.stars }}</p>
                    </div>
                    <button class="flex items-center gap-1 rounded-lg border px-3 py-2 text-sm font-semibold hover:bg-muted" @click="review('redemptions', r.id, 'reject')">
                        <X class="size-4" /> Ablehnen
                    </button>
                    <button class="flex items-center gap-1 rounded-lg bg-emerald-600 px-3 py-2 text-sm font-bold text-white hover:bg-emerald-700" @click="review('redemptions', r.id, 'approve')">
                        <Check class="size-4" /> Einlösen
                    </button>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
