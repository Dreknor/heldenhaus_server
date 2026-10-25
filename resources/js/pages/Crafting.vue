<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import type { SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { Anvil, Boxes, Clock3, Gift, Hammer, Handshake, Plus, ScrollText, Trash2 } from 'lucide-vue-next';
import { computed, onMounted, onUnmounted, ref } from 'vue';

interface StockItem {
    id: number;
    key: string;
    name: string;
    icon: string;
    type: string;
    quantity: number;
    master_quantity: number;
}

interface RecipeItem {
    key: string;
    name: string;
    icon: string;
    quantity: number;
    have: number;
}

interface Recipe {
    key: string;
    output: RecipeItem;
    station: string;
    station_level: number;
    level: number;
    unlock: string;
    learn: RecipeItem[] | null;
    inputs: RecipeItem[];
    gold: number;
    seconds: number;
    unlocked: boolean;
    station_ok: boolean;
    has_materials: boolean;
}

interface TradeItem {
    item_id: number;
    name: string;
    icon: string;
    quantity: number;
}

interface Trade {
    id: number;
    own: boolean;
    from: { id: number; name: string | null };
    accepted_by: { id: number; name: string } | null;
    offer: TradeItem[];
    want: TradeItem[];
    status: string;
    can_approve: boolean;
    created_at: string;
}

const props = defineProps<{
    crafting: {
        level: number;
        xp: number;
        xp_next: number;
        max_jobs: number;
        jobs: {
            id: number;
            recipe_key: string;
            item: { name: string; icon: string };
            quantity: number;
            quality: string;
            finishes_at: string;
            ready: boolean;
        }[];
        recipes: Recipe[];
    };
    storage: { item_id: number; key: string; name: string; icon: string; quantity: number }[];
    libraryAvailable: boolean;
    members: { id: number; name: string }[];
    items: StockItem[];
    alliances: { id: number; name: string }[];
    trades: Trade[];
}>();

const page = usePage<SharedData>();
const tab = ref<'recipes' | 'jobs' | 'storage' | 'gifts' | 'trades'>('recipes');
const tabs = [
    { key: 'recipes', title: 'Rezepte', icon: ScrollText },
    { key: 'jobs', title: 'Aufträge', icon: Hammer },
    { key: 'storage', title: 'Familienlager', icon: Boxes },
    { key: 'gifts', title: 'Schenken', icon: Gift },
    { key: 'trades', title: 'Tauschbörse', icon: Handshake },
] as const;
const tradeSides = ['offer', 'want'] as const;

const craftForm = useForm({ recipe: '' });
const learnForm = useForm({ recipe: '' });
const storageForm = useForm({ item_id: 0, quantity: 1 });
const giftForm = useForm({ item_id: 0, quantity: 1, to: 0, master: false });
const tradeForm = useForm({ alliance_id: 0, offer: {} as Record<number, number>, want: {} as Record<number, number> });
const offerRows = ref([{ item_id: 0, quantity: 1 }]);
const wantRows = ref([{ item_id: 0, quantity: 1 }]);
const currentTime = ref(Date.now());
let clockInterval: ReturnType<typeof setInterval> | undefined;

const ordinaryItems = computed(() => props.items.filter((item) => item.quantity > 0));
const materialItems = computed(() => ordinaryItems.value.filter((item) => item.type === 'material'));
const qualityLabels: Record<string, string> = { normal: 'normal', good: 'gut', master: 'meisterhaft' };
const statusLabels: Record<string, string> = {
    awaiting_parent: 'Wartet auf deine Eltern',
    open: 'Offen',
    awaiting_partner_parent: 'Wartet auf die Eltern der anderen Familie',
    done: 'Abgeschlossen',
    rejected: 'Abgelehnt',
    cancelled: 'Zurückgezogen',
};
const timeLabel = (value: string) => new Intl.DateTimeFormat('de-DE', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value));
const jobIsReady = (job: (typeof props.crafting.jobs)[number]) => job.ready || new Date(job.finishes_at).getTime() <= currentTime.value;
const durationLabel = (seconds: number) => {
    if (seconds < 60) return `${seconds} Sek.`;
    if (seconds < 3600) return `${Math.ceil(seconds / 60)} Min.`;
    return `${Math.ceil(seconds / 3600)} Std.`;
};
const gameError = computed(() => page.props.errors?.game);

onMounted(() => {
    clockInterval = setInterval(() => (currentTime.value = Date.now()), 15000);
});
onUnmounted(() => {
    if (clockInterval) clearInterval(clockInterval);
});

function startCraft(recipe: Recipe) {
    craftForm.recipe = recipe.key;
    craftForm.post(route('crafting.craft'), { preserveScroll: true });
}

function learnRecipe(recipe: Recipe) {
    learnForm.recipe = recipe.key;
    learnForm.post(route('crafting.learn'), { preserveScroll: true });
}

function submitStorage(action: 'deposit' | 'withdraw', itemId: number, max: number) {
    storageForm.item_id = itemId;
    storageForm.quantity = Math.min(Math.max(storageForm.quantity, 1), max);
    storageForm.post(route(`crafting.storage.${action}`), { preserveScroll: true });
}

function sendGift() {
    giftForm.post(route('crafting.gifts.store'), { preserveScroll: true, onSuccess: () => giftForm.reset('quantity', 'master') });
}

function addTradeRow(side: 'offer' | 'want') {
    const rows = side === 'offer' ? offerRows : wantRows;
    if (rows.value.length < 5) rows.value.push({ item_id: 0, quantity: 1 });
}

function removeTradeRow(side: 'offer' | 'want', index: number) {
    const rows = side === 'offer' ? offerRows : wantRows;
    if (rows.value.length > 1) rows.value.splice(index, 1);
}

function submitTrade() {
    const toAmounts = (rows: typeof offerRows.value) => {
        const result: Record<number, number> = {};
        for (const row of rows) {
            if (row.item_id) result[row.item_id] = (result[row.item_id] ?? 0) + row.quantity;
        }
        return result;
    };
    tradeForm.offer = toAmounts(offerRows.value);
    tradeForm.want = toAmounts(wantRows.value);
    tradeForm.post(route('crafting.trades.store'), {
        preserveScroll: true,
        onSuccess: () => {
            offerRows.value = [{ item_id: 0, quantity: 1 }];
            wantRows.value = [{ item_id: 0, quantity: 1 }];
            tradeForm.reset('alliance_id', 'offer', 'want');
        },
    });
}

function tradeAction(trade: Trade, action: 'accept' | 'approve' | 'reject' | 'cancel') {
    router.post(route('crafting.trades.action', { trade: trade.id, action }), {}, { preserveScroll: true });
}

function itemOptions(rows: { item_id: number }[], currentIndex: number) {
    const selectedElsewhere = new Set(rows.filter((_, index) => index !== currentIndex).map((row) => row.item_id));
    return ordinaryItems.value.filter((item) => !selectedElsewhere.has(item.id));
}
</script>

<template>
    <Head title="Handwerk & Tausch" />

    <AppLayout :breadcrumbs="[{ title: 'Handwerk & Tausch', href: '/crafting' }]">
        <div class="flex min-w-0 flex-1 flex-col gap-5 p-4 md:p-6">
            <header
                class="flex flex-wrap items-center gap-4 rounded-2xl border bg-gradient-to-br from-amber-50 to-orange-100 p-5 dark:from-amber-950/40 dark:to-orange-950/30"
            >
                <div class="grid size-14 place-items-center rounded-2xl bg-white/70 text-amber-700 dark:bg-black/20 dark:text-amber-300">
                    <Anvil class="size-8" />
                </div>
                <div class="min-w-0 flex-1">
                    <h1 class="text-2xl font-extrabold tracking-tight">Handwerk & Tausch</h1>
                    <p class="text-sm text-muted-foreground">Handwerk Stufe {{ crafting.level }} · {{ crafting.xp }}/{{ crafting.xp_next }} XP</p>
                </div>
                <div class="w-full max-w-48 rounded-full bg-white/70 p-1 dark:bg-black/20">
                    <div
                        class="h-2 rounded-full bg-amber-500"
                        :style="{ width: `${Math.min(100, (crafting.xp / Math.max(1, crafting.xp_next)) * 100)}%` }"
                    />
                </div>
            </header>

            <div
                v-if="page.props.flash?.success"
                role="status"
                class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-200"
            >
                {{ page.props.flash.success }}
            </div>
            <div
                v-if="gameError"
                role="alert"
                class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-200"
            >
                {{ gameError }}
            </div>

            <nav class="flex gap-2 overflow-x-auto pb-1" aria-label="Handwerksbereiche">
                <button
                    v-for="item in tabs"
                    :key="item.key"
                    class="flex shrink-0 items-center gap-2 rounded-xl px-3 py-2 text-sm font-bold transition"
                    :class="tab === item.key ? 'bg-primary text-primary-foreground' : 'border bg-card hover:bg-muted'"
                    @click="tab = item.key"
                >
                    <component :is="item.icon" class="size-4" /> {{ item.title }}
                </button>
            </nav>

            <section v-if="tab === 'recipes'" class="grid gap-3">
                <div class="flex items-center gap-2">
                    <Hammer class="size-5 text-amber-600" />
                    <h2 class="font-extrabold">Rezeptbuch</h2>
                </div>
                <p v-if="!crafting.recipes.length" class="rounded-2xl border border-dashed p-8 text-center text-muted-foreground">
                    Es sind noch keine Rezepte verfügbar.
                </p>
                <article
                    v-for="recipe in crafting.recipes"
                    :key="recipe.key"
                    class="grid gap-4 rounded-2xl border bg-card p-4 sm:grid-cols-[1fr_auto] sm:items-center"
                >
                    <div class="min-w-0">
                        <div class="flex items-center gap-2 font-extrabold">
                            <span class="text-2xl" aria-hidden="true">{{ recipe.output.icon }}</span
                            ><span>{{ recipe.output.name }}</span>
                        </div>
                        <p class="mt-1 text-xs text-muted-foreground">
                            {{ recipe.station }} Stufe {{ recipe.station_level }} · Handwerk Stufe {{ recipe.level }} ·
                            <template v-if="recipe.seconds">⏱ {{ durationLabel(recipe.seconds) }}</template
                            ><template v-else>sofort</template>
                            <template v-if="recipe.gold"> · {{ recipe.gold }} Gold</template>
                        </p>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <span
                                v-for="input in recipe.inputs"
                                :key="input.key"
                                class="rounded-lg bg-muted px-2 py-1 text-xs"
                                :class="input.have < input.quantity ? 'text-rose-600' : ''"
                            >
                                {{ input.icon }} {{ input.name }} {{ input.have }}/{{ input.quantity }}
                            </span>
                        </div>
                        <p v-if="!recipe.unlocked" class="mt-2 text-xs font-semibold text-muted-foreground">
                            {{
                                recipe.unlock === 'library'
                                    ? libraryAvailable
                                        ? 'Benötigt Bibliothek und Pergament/Tinte zum Lernen.'
                                        : 'Benötigt eine Bibliothek zum Lernen.'
                                    : `Freischaltung ab Handwerk Stufe ${recipe.level}.`
                            }}
                        </p>
                        <p v-else-if="!recipe.station_ok" class="mt-2 text-xs font-semibold text-rose-600">
                            Die benötigte Werkstatt ist noch nicht gebaut oder ausgebaut.
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2 sm:justify-end">
                        <Button
                            v-if="recipe.unlock === 'library' && !recipe.unlocked"
                            variant="outline"
                            :disabled="!libraryAvailable || learnForm.processing"
                            @click="learnRecipe(recipe)"
                            >Rezept lernen</Button
                        >
                        <Button
                            v-else
                            :disabled="
                                !recipe.unlocked ||
                                !recipe.station_ok ||
                                !recipe.has_materials ||
                                crafting.jobs.length >= crafting.max_jobs ||
                                craftForm.processing
                            "
                            @click="startCraft(recipe)"
                            >Herstellen</Button
                        >
                    </div>
                </article>
            </section>

            <section v-else-if="tab === 'jobs'" class="grid gap-3">
                <div class="flex items-center gap-2">
                    <Clock3 class="size-5 text-sky-600" />
                    <h2 class="font-extrabold">Laufende Aufträge</h2>
                    <span class="ml-auto text-xs text-muted-foreground">{{ crafting.jobs.length }}/{{ crafting.max_jobs }} Plätze belegt</span>
                </div>
                <article v-for="job in crafting.jobs" :key="job.id" class="flex flex-wrap items-center gap-3 rounded-2xl border bg-card p-4">
                    <span class="text-3xl" aria-hidden="true">{{ job.item.icon }}</span>
                    <div class="min-w-0 flex-1">
                        <p class="font-bold">{{ job.item.name }} × {{ job.quantity }}</p>
                        <p class="text-xs text-muted-foreground">
                            Qualität: {{ qualityLabels[job.quality] ?? job.quality }} ·
                            {{ jobIsReady(job) ? 'Fertig' : `Fertig um ${timeLabel(job.finishes_at)}` }}
                        </p>
                    </div>
                    <Button
                        v-if="jobIsReady(job)"
                        :disabled="craftForm.processing"
                        @click="router.post(route('crafting.jobs.collect', { job: job.id }), {}, { preserveScroll: true })"
                        >Abholen</Button
                    >
                    <span v-else class="rounded-full bg-sky-100 px-3 py-1 text-xs font-bold text-sky-700 dark:bg-sky-950 dark:text-sky-300"
                        >In Arbeit</span
                    >
                </article>
                <p v-if="!crafting.jobs.length" class="rounded-2xl border border-dashed p-8 text-center text-muted-foreground">
                    Keine offenen Aufträge. Wähle ein Rezept und starte eine Herstellung.
                </p>
            </section>

            <section v-else-if="tab === 'storage'" class="grid gap-4 lg:grid-cols-2">
                <div class="grid content-start gap-3 rounded-2xl border bg-card p-5">
                    <div>
                        <h2 class="flex items-center gap-2 font-extrabold"><Boxes class="size-5" /> Im Familienlager</h2>
                        <p class="text-sm text-muted-foreground">Nur Materialien können eingelagert werden.</p>
                    </div>
                    <article v-for="item in storage" :key="item.item_id" class="flex items-center gap-3 rounded-xl border p-3">
                        <span class="text-2xl">{{ item.icon }}</span
                        ><span class="min-w-0 flex-1 font-semibold"
                            >{{ item.name }} <span class="text-muted-foreground">× {{ item.quantity }}</span></span
                        >
                        <Input
                            v-model.number="storageForm.quantity"
                            type="number"
                            min="1"
                            :max="item.quantity"
                            class="w-20"
                            aria-label="Menge zum Entnehmen"
                        />
                        <Button
                            size="sm"
                            variant="outline"
                            :disabled="storageForm.processing"
                            @click="submitStorage('withdraw', item.item_id, item.quantity)"
                            >Nehmen</Button
                        >
                    </article>
                    <p v-if="!storage.length" class="text-sm text-muted-foreground">Das Lager ist noch leer.</p>
                </div>

                <div class="grid content-start gap-3 rounded-2xl border bg-card p-5">
                    <div>
                        <h2 class="font-extrabold">Material einlagern</h2>
                        <p class="text-sm text-muted-foreground">Deine Materialien stehen danach allen Familienmitgliedern zur Verfügung.</p>
                    </div>
                    <article v-for="item in materialItems" :key="item.id" class="flex items-center gap-3 rounded-xl border p-3">
                        <span class="text-2xl">{{ item.icon }}</span
                        ><span class="min-w-0 flex-1 font-semibold"
                            >{{ item.name }} <span class="text-muted-foreground">× {{ item.quantity }}</span></span
                        >
                        <Input
                            v-model.number="storageForm.quantity"
                            type="number"
                            min="1"
                            :max="item.quantity"
                            class="w-20"
                            aria-label="Menge zum Einlagern"
                        />
                        <Button
                            size="sm"
                            variant="outline"
                            :disabled="storageForm.processing"
                            @click="submitStorage('deposit', item.id, item.quantity)"
                            >Einlagern</Button
                        >
                    </article>
                    <p v-if="!materialItems.length" class="text-sm text-muted-foreground">Du hast keine Materialien zum Einlagern.</p>
                </div>
            </section>

            <section v-else-if="tab === 'gifts'" class="mx-auto grid w-full max-w-2xl gap-4 rounded-2xl border bg-card p-5">
                <div>
                    <h2 class="flex items-center gap-2 font-extrabold"><Gift class="size-5 text-pink-600" /> Gegenstand verschenken</h2>
                    <p class="text-sm text-muted-foreground">Schenken funktioniert nur an andere Mitglieder deiner Familie.</p>
                </div>
                <form class="grid gap-3" @submit.prevent="sendGift">
                    <label class="grid gap-1 text-sm font-semibold"
                        >Empfänger
                        <select v-model.number="giftForm.to" required class="h-10 rounded-md border border-input bg-background px-3 font-normal">
                            <option :value="0" disabled>Familienmitglied wählen …</option>
                            <option v-for="member in members" :key="member.id" :value="member.id">{{ member.name }}</option>
                        </select>
                    </label>
                    <label class="grid gap-1 text-sm font-semibold"
                        >Gegenstand
                        <select v-model.number="giftForm.item_id" required class="h-10 rounded-md border border-input bg-background px-3 font-normal">
                            <option :value="0" disabled>Gegenstand wählen …</option>
                            <option v-for="item in items.filter((i) => i.quantity + i.master_quantity > 0)" :key="item.id" :value="item.id">
                                {{ item.icon }} {{ item.name }} ({{ item.quantity }} normal, {{ item.master_quantity }} meisterhaft)
                            </option>
                        </select>
                    </label>
                    <div class="flex flex-wrap items-center gap-3">
                        <label class="grid gap-1 text-sm font-semibold"
                            >Menge <Input v-model.number="giftForm.quantity" type="number" min="1" max="999" class="w-28"
                        /></label>
                        <label v-if="items.find((i) => i.id === giftForm.item_id)?.master_quantity" class="mt-5 flex items-center gap-2 text-sm">
                            <input v-model="giftForm.master" type="checkbox" class="size-4" /> Meisterhafte Qualität verschenken
                        </label>
                    </div>
                    <InputError :message="giftForm.errors.item_id || giftForm.errors.quantity || giftForm.errors.to" />
                    <Button type="submit" :disabled="giftForm.processing || !members.length || !ordinaryItems.length">Verschenken</Button>
                    <p v-if="!members.length" class="text-sm text-muted-foreground">Du bist das einzige Familienmitglied.</p>
                </form>
            </section>

            <section v-else class="grid gap-5 lg:grid-cols-[minmax(18rem,0.8fr)_minmax(0,1.2fr)]">
                <div class="grid content-start gap-3 rounded-2xl border bg-card p-5">
                    <div>
                        <h2 class="flex items-center gap-2 font-extrabold"><Handshake class="size-5 text-violet-600" /> Neues Tauschangebot</h2>
                        <p class="text-sm text-muted-foreground">
                            Gegenstände tauschen – kein Gold, keine Sterne. Angebote sind auf fünf Sorten pro Seite begrenzt.
                        </p>
                    </div>
                    <form class="grid gap-4" @submit.prevent="submitTrade">
                        <label class="grid gap-1 text-sm font-semibold"
                            >Bündnisfamilie
                            <select
                                v-model.number="tradeForm.alliance_id"
                                required
                                class="h-10 rounded-md border border-input bg-background px-3 font-normal"
                            >
                                <option :value="0" disabled>Familie wählen …</option>
                                <option v-for="alliance in alliances" :key="alliance.id" :value="alliance.id">{{ alliance.name }}</option>
                            </select>
                        </label>
                        <div v-for="side in tradeSides" :key="side" class="grid gap-2">
                            <div class="flex items-center justify-between">
                                <h3 class="text-sm font-bold">{{ side === 'offer' ? 'Ich biete' : 'Ich möchte' }}</h3>
                                <button
                                    type="button"
                                    class="flex items-center gap-1 text-xs font-bold text-primary"
                                    :disabled="(side === 'offer' ? offerRows : wantRows).length >= 5"
                                    @click="addTradeRow(side)"
                                >
                                    <Plus class="size-4" /> Sorte
                                </button>
                            </div>
                            <div v-for="(row, index) in side === 'offer' ? offerRows : wantRows" :key="index" class="flex gap-2">
                                <select
                                    v-model.number="row.item_id"
                                    required
                                    class="h-10 min-w-0 flex-1 rounded-md border border-input bg-background px-2 text-sm"
                                >
                                    <option :value="0" disabled>Gegenstand …</option>
                                    <option
                                        v-for="item in itemOptions(side === 'offer' ? offerRows : wantRows, index)"
                                        :key="item.id"
                                        :value="item.id"
                                    >
                                        {{ item.icon }} {{ item.name }}{{ side === 'offer' ? ` (×${item.quantity})` : '' }}
                                    </option>
                                </select>
                                <Input v-model.number="row.quantity" type="number" min="1" max="999" class="w-20" aria-label="Menge" />
                                <button
                                    v-if="(side === 'offer' ? offerRows : wantRows).length > 1"
                                    type="button"
                                    class="rounded-md p-2 text-muted-foreground hover:bg-muted"
                                    aria-label="Sorte entfernen"
                                    @click="removeTradeRow(side, index)"
                                >
                                    <Trash2 class="size-4" />
                                </button>
                            </div>
                        </div>
                        <InputError :message="tradeForm.errors.alliance_id || tradeForm.errors.offer || tradeForm.errors.want" />
                        <Button type="submit" :disabled="tradeForm.processing || !alliances.length || !ordinaryItems.length"
                            >Angebot veröffentlichen</Button
                        >
                        <p v-if="!alliances.length" class="text-sm text-muted-foreground">Schließe zuerst ein Bündnis mit einer anderen Familie.</p>
                    </form>
                </div>

                <div class="grid content-start gap-3">
                    <h2 class="font-extrabold">Angebote im Bündnis</h2>
                    <article v-for="trade in trades" :key="trade.id" class="grid gap-3 rounded-2xl border bg-card p-4">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="flex-1 font-bold">{{ trade.own ? 'Dein Angebot' : `Angebot von ${trade.from.name ?? 'Familienmitglied'}` }}</p>
                            <span class="rounded-full bg-muted px-2.5 py-1 text-xs font-bold">{{ statusLabels[trade.status] ?? trade.status }}</span>
                        </div>
                        <div class="grid gap-2 text-sm sm:grid-cols-[1fr_auto_1fr] sm:items-center">
                            <p class="rounded-lg bg-muted p-3">
                                <strong>Bietet:</strong> {{ trade.offer.map((i) => `${i.icon} ${i.name} × ${i.quantity}`).join(', ') }}
                            </p>
                            <span class="text-center text-lg font-bold text-muted-foreground">⇄</span>
                            <p class="rounded-lg bg-muted p-3">
                                <strong>Möchte:</strong> {{ trade.want.map((i) => `${i.icon} ${i.name} × ${i.quantity}`).join(', ') }}
                            </p>
                        </div>
                        <p v-if="trade.accepted_by" class="text-xs text-muted-foreground">Angenommen von {{ trade.accepted_by.name }}</p>
                        <div class="flex flex-wrap gap-2">
                            <Button v-if="!trade.own && trade.status === 'open'" size="sm" @click="tradeAction(trade, 'accept')">Annehmen</Button>
                            <template v-if="trade.can_approve">
                                <Button size="sm" @click="tradeAction(trade, 'approve')">Genehmigen</Button>
                                <Button size="sm" variant="outline" @click="tradeAction(trade, 'reject')">Ablehnen</Button>
                            </template>
                            <Button
                                v-if="trade.own && ['open', 'awaiting_parent'].includes(trade.status)"
                                size="sm"
                                variant="outline"
                                @click="tradeAction(trade, 'cancel')"
                                >Zurückziehen</Button
                            >
                        </div>
                    </article>
                    <p v-if="!trades.length" class="rounded-2xl border border-dashed p-8 text-center text-sm text-muted-foreground">
                        Aktuell gibt es keine sichtbaren Angebote in deinen Bündnissen.
                    </p>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
