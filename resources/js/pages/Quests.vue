<script setup lang="ts">
import QuestCard, { type ActiveQuest } from '@/components/game/QuestCard.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { Coins, Sparkles, Swords, Users, User } from 'lucide-vue-next';
import { ref } from 'vue';

interface Offer {
    key: string;
    type: 'boss' | 'collect';
    name: string;
    icon: string;
    description: string;
    goal: number;
    days: number;
    weakness?: string;
    resist?: string;
    rage?: number;
    categories?: string[];
    item_name?: string;
    item_icon?: string;
    rewards: { gold?: number; xp?: number; item?: string };
}

const props = defineProps<{
    family: ActiveQuest | null;
    solo: ActiveQuest | null;
    familyOffers: Offer[];
    soloOffers: Offer[];
    canStartFamily: boolean;
    categories: Record<string, string>;
    history: { id: number; name: string; icon: string; scope: string; status: string; date: string }[];
}>();

const rewardText = ref('');

function start(offer: Offer, scope: 'family' | 'solo') {
    const what = scope === 'family' ? 'für die ganze Familie' : 'als Solo-Quest';
    if (!confirm(`„${offer.name}“ ${what} starten?`)) return;
    router.post(route('quests.start'), { quest: offer.key, scope, reward_text: scope === 'family' ? rewardText.value || null : null }, { preserveScroll: true });
}

function abandon(quest: ActiveQuest) {
    if (!confirm(`„${quest.name}“ wirklich abbrechen? Der Fortschritt geht verloren.`)) return;
    router.post(route('quests.abandon', { quest: quest.id }), {}, { preserveScroll: true });
}

const statusLabel: Record<string, string> = { completed: '✅ geschafft', expired: '⌛ abgelaufen', abandoned: '✖ abgebrochen' };
const cat = (key?: string) => (key ? (props.categories[key] ?? key) : '');
</script>

<template>
    <Head title="Quests" />

    <AppLayout :breadcrumbs="[{ title: 'Quests', href: '/quests' }]">
        <div class="flex min-w-0 flex-1 flex-col gap-8 p-4 md:p-6">
            <div>
                <h1 class="text-2xl font-extrabold tracking-tight">Quests</h1>
                <p class="text-sm text-muted-foreground">
                    Jede erledigte Aufgabe bringt eure Quests voran: Bosse bekommen Schaden, bei Sammel-Quests findet ihr Gegenstände. Die Quest-Tafel wechselt jede Woche.
                </p>
            </div>

            <!-- Familie -->
            <section class="grid gap-3">
                <h2 class="flex items-center gap-2 text-lg font-extrabold"><Users class="size-5" /> Gemeinsam als Familie</h2>
                <template v-if="family">
                    <QuestCard :quest="family" />
                    <button v-if="canStartFamily" class="justify-self-start text-xs text-muted-foreground underline" @click="abandon(family)">Familien-Quest abbrechen</button>
                </template>
                <template v-else>
                    <p v-if="!canStartFamily" class="text-sm text-muted-foreground">Gerade läuft keine Familien-Quest – frag deine Eltern, welche ihr als Nächstes angeht!</p>
                    <div v-if="canStartFamily" class="flex flex-wrap items-end gap-2">
                        <div class="grid min-w-64 gap-1">
                            <label for="reward" class="text-sm font-semibold">Gemeinsame Belohnung bei Erfolg (optional)</label>
                            <Input id="reward" v-model="rewardText" placeholder="z. B. Pizza-Abend" />
                        </div>
                    </div>
                    <div class="grid gap-3 md:grid-cols-3">
                        <article v-for="o in familyOffers" :key="o.key" class="flex flex-col gap-2 rounded-2xl border bg-card p-4">
                            <div class="flex items-center gap-3">
                                <span class="text-5xl">{{ o.icon }}</span>
                                <div>
                                    <p class="font-extrabold">{{ o.name }}</p>
                                    <p class="text-xs text-muted-foreground">
                                        <template v-if="o.type === 'boss'"><Swords class="inline size-3" /> Boss · {{ o.goal }} HP</template>
                                        <template v-else>{{ o.item_icon }} Sammle {{ o.goal }} {{ o.item_name }}</template>
                                        · {{ o.days }} Tage
                                    </p>
                                </div>
                            </div>
                            <p class="text-sm">{{ o.description }}</p>
                            <div class="flex flex-wrap gap-1.5 text-[11px] font-semibold">
                                <span v-if="o.weakness" class="rounded-full bg-muted px-2 py-0.5">💥 {{ cat(o.weakness) }}</span>
                                <span v-if="o.resist" class="rounded-full bg-muted px-2 py-0.5">🛡️ {{ cat(o.resist) }}</span>
                                <span v-if="o.rage" class="rounded-full bg-muted px-2 py-0.5">😠 Wut ×{{ o.rage }}</span>
                                <span v-if="o.categories?.length" class="rounded-full bg-muted px-2 py-0.5">nur {{ o.categories.map(cat).join(', ') }}</span>
                            </div>
                            <p class="mt-auto flex items-center gap-2 text-xs font-semibold text-amber-600">
                                <Coins class="size-3.5" /> {{ o.rewards.gold }} · <Sparkles class="size-3.5" /> {{ o.rewards.xp }} XP für alle
                            </p>
                            <Button v-if="canStartFamily" @click="start(o, 'family')">Gemeinsam starten</Button>
                        </article>
                    </div>
                </template>
            </section>

            <!-- Solo -->
            <section class="grid gap-3">
                <h2 class="flex items-center gap-2 text-lg font-extrabold"><User class="size-5" /> Dein Solo-Abenteuer</h2>
                <template v-if="solo">
                    <QuestCard :quest="solo" />
                    <button class="justify-self-start text-xs text-muted-foreground underline" @click="abandon(solo)">Solo-Quest abbrechen</button>
                </template>
                <div v-else class="grid gap-3 md:grid-cols-3">
                    <article v-for="o in soloOffers" :key="o.key" class="flex flex-col gap-2 rounded-2xl border bg-card p-4">
                        <div class="flex items-center gap-3">
                            <span class="text-5xl">{{ o.icon }}</span>
                            <div>
                                <p class="font-extrabold">{{ o.name }}</p>
                                <p class="text-xs text-muted-foreground">
                                    <template v-if="o.type === 'boss'">Boss · {{ o.goal }} HP</template>
                                    <template v-else>{{ o.item_icon }} Sammle {{ o.goal }} {{ o.item_name }}</template>
                                    · {{ o.days }} Tage
                                </p>
                            </div>
                        </div>
                        <p class="text-sm">{{ o.description }}</p>
                        <div class="flex flex-wrap gap-1.5 text-[11px] font-semibold">
                            <span v-if="o.weakness" class="rounded-full bg-muted px-2 py-0.5">💥 {{ cat(o.weakness) }}</span>
                            <span v-if="o.categories?.length" class="rounded-full bg-muted px-2 py-0.5">nur {{ o.categories.map(cat).join(', ') }}</span>
                        </div>
                        <p class="mt-auto flex items-center gap-2 text-xs font-semibold text-amber-600">
                            <Coins class="size-3.5" /> {{ o.rewards.gold }} · <Sparkles class="size-3.5" /> {{ o.rewards.xp }} XP
                        </p>
                        <Button variant="outline" @click="start(o, 'solo')">Solo starten</Button>
                    </article>
                    <p v-if="!soloOffers.length" class="text-sm text-muted-foreground">Gerade keine Solo-Quests verfügbar.</p>
                </div>
            </section>

            <section v-if="history.length" class="grid gap-2">
                <h2 class="font-extrabold">Bisherige Abenteuer</h2>
                <div class="divide-y rounded-xl border bg-card">
                    <div v-for="h in history" :key="h.id" class="flex items-center gap-3 p-3 text-sm">
                        <span class="text-xl">{{ h.icon }}</span>
                        <span class="font-semibold">{{ h.name }}</span>
                        <span class="text-xs text-muted-foreground">{{ h.scope === 'family' ? 'Familie' : 'Solo' }} · {{ h.date }}</span>
                        <span class="ml-auto text-xs font-bold">{{ statusLabel[h.status] }}</span>
                    </div>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
