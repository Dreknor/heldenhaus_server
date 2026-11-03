<script setup lang="ts">
import ItemIcon from '@/components/game/ItemIcon.vue';
import PetArt from '@/components/game/PetArt.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import type { SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/vue3';
import { Coins, Egg } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Item {
    id: number;
    name: string;
    description: string | null;
    type: string;
    slot: string | null;
    rarity: string;
    icon: string;
    stats: Record<string, unknown> | null;
    price_gold: number | null;
    min_level: number;
    quantity?: number;
    equipped?: boolean;
}

const props = defineProps<{
    items: Item[];
    shop: Item[];
    pets: { id: number; name: string; egg_icon: string; potion_icon: string; egg_key: string; potion_key: string; growth: number; growth_max: number; is_mount: boolean }[];
    types: Record<string, string>;
    rarities: Record<string, string>;
    level: number;
}>();

const page = usePage<SharedData>();
const gold = computed(() => page.props.auth.stats?.gold ?? 0);
const tab = ref<'bag' | 'shop' | 'stable'>('bag');

const groups = computed(() => {
    const result: Record<string, Item[]> = {};
    for (const item of props.items) (result[item.type] ??= []).push(item);
    return result;
});
const eggs = computed(() => props.items.filter((i) => i.type === 'egg'));
const potions = computed(() => props.items.filter((i) => i.type === 'hatching_potion'));
const food = computed(() => props.items.filter((i) => i.type === 'food'));
const owned = computed(() => new Set(props.items.map((i) => i.id)));

const egg = ref<number | null>(null);
const potion = ref<number | null>(null);
const feedFor = ref<number | null>(null);

const post = (name: string, params: Record<string, number>, data: Record<string, number | null> = {}) => router.post(route(name, params), data, { preserveScroll: true });

function hatch() {
    if (egg.value && potion.value) post('pets.hatch', {}, { egg_id: egg.value, potion_id: potion.value });
}

const statNames: Record<string, string> = { str: 'Kraft', int: 'Köpfchen', con: 'Ausdauer', per: 'Spürsinn', heal: 'HP', mana: 'Mana' };
// Nur Werte-Boni zeigen; Größe (w/h), Lieblingstiere, Farben u. Ä. sind keine Boni
const statText = (stats: Record<string, unknown> | null) =>
    Object.entries(stats ?? {})
        .filter(([k, v]) => typeof v === 'number' && k !== 'w' && k !== 'h')
        .map(([k, v]) => `+${v} ${statNames[k] ?? k}`)
        .join(', ');

const rarityRing: Record<string, string> = {
    common: 'ring-slate-200 dark:ring-slate-700',
    uncommon: 'ring-emerald-300',
    rare: 'ring-sky-400',
    epic: 'ring-violet-500',
    legendary: 'ring-amber-400 shadow-md shadow-amber-200',
};
const tabClass = (active: boolean) => `rounded-lg px-4 py-2 text-sm font-bold transition ${active ? 'bg-primary text-primary-foreground' : 'hover:bg-muted'}`;
</script>

<template>
    <Head title="Inventar" />

    <AppLayout :breadcrumbs="[{ title: 'Inventar', href: '/inventory' }]">
        <div class="flex min-w-0 flex-1 flex-col gap-5 p-4 md:p-6">
            <div class="flex flex-wrap items-center gap-2">
                <h1 class="mr-auto text-2xl font-extrabold tracking-tight">Inventar</h1>
                <button :class="tabClass(tab === 'bag')" @click="tab = 'bag'">Rucksack</button>
                <button :class="tabClass(tab === 'shop')" @click="tab = 'shop'">Gold-Shop</button>
                <button :class="tabClass(tab === 'stable')" @click="tab = 'stable'">Stall ({{ pets.length }})</button>
            </div>

            <!-- Rucksack -->
            <template v-if="tab === 'bag'">
                <p v-if="!items.length" class="rounded-2xl border border-dashed p-10 text-center text-muted-foreground">
                    Noch leer. Erledige Aufgaben – manchmal findest du dabei Eier, Futter oder Materialien!
                </p>
                <section v-for="(list, type) in groups" :key="type" class="grid gap-3">
                    <h2 class="font-extrabold">{{ types[type] ?? type }}</h2>
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-6">
                        <div v-for="item in list" :key="item.id" class="relative flex flex-col items-center gap-1 rounded-2xl bg-card p-3 text-center ring-2" :class="rarityRing[item.rarity]">
                            <ItemIcon :item="item" :size="56" />
                            <span class="line-clamp-2 text-xs font-semibold">{{ item.name }}</span>
                            <span v-if="item.stats" class="text-[11px] text-muted-foreground">{{ statText(item.stats) }}</span>
                            <span class="absolute right-1.5 top-1.5 rounded-full bg-foreground px-1.5 text-[11px] font-bold text-background">×{{ item.quantity }}</span>
                            <button
                                v-if="item.type === 'equipment'"
                                class="mt-1 rounded-md px-2 py-1 text-xs font-bold"
                                :class="item.equipped ? 'bg-emerald-100 text-emerald-700' : 'bg-primary text-primary-foreground'"
                                :disabled="item.equipped"
                                @click="post('inventory.equip', { item: item.id })"
                            >
                                {{ item.equipped ? 'angelegt' : 'Anlegen' }}
                            </button>
                            <button v-if="item.type === 'potion'" class="mt-1 rounded-md bg-rose-500 px-2 py-1 text-xs font-bold text-white" @click="post('inventory.use', { item: item.id })">
                                Trinken
                            </button>
                        </div>
                    </div>
                </section>
            </template>

            <!-- Gold-Shop -->
            <template v-if="tab === 'shop'">
                <p class="flex items-center gap-2 font-bold text-amber-600"><Coins class="size-5" /> {{ gold.toFixed(0) }} Gold</p>
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    <div v-for="item in shop" :key="item.id" class="flex items-center gap-3 rounded-2xl border bg-card p-4">
                        <ItemIcon :item="item" :size="52" framed />
                        <div class="min-w-0 flex-1">
                            <p class="font-bold">{{ item.name }}</p>
                            <p class="text-xs text-muted-foreground">
                                {{ types[item.type] }}<template v-if="item.stats"> · {{ statText(item.stats) }}</template>
                                <template v-if="item.min_level > level"> · ab Level {{ item.min_level }}</template>
                            </p>
                        </div>
                        <span v-if="item.type === 'equipment' && owned.has(item.id)" class="text-xs font-bold text-emerald-600">gekauft</span>
                        <Button
                            v-else
                            size="sm"
                            class="bg-amber-500 hover:bg-amber-600"
                            :disabled="gold < (item.price_gold ?? 0) || item.min_level > level"
                            @click="post('inventory.buy', { item: item.id })"
                        >
                            <Coins class="size-4" /> {{ item.price_gold }}
                        </Button>
                    </div>
                </div>
            </template>

            <!-- Stall -->
            <template v-if="tab === 'stable'">
                <section class="grid gap-3 rounded-2xl border bg-card p-5">
                    <h2 class="flex items-center gap-2 font-extrabold"><Egg class="size-5" /> Ausbrüten</h2>
                    <p v-if="!eggs.length || !potions.length" class="text-sm text-muted-foreground">Du brauchst ein Ei und ein Schlüpfpulver – beides findest du beim Erledigen von Aufgaben.</p>
                    <div v-else class="flex flex-wrap items-center gap-2">
                        <select v-model="egg" class="h-10 rounded-md border border-input bg-background px-3 text-sm">
                            <option :value="null">Ei wählen …</option>
                            <option v-for="e in eggs" :key="e.id" :value="e.id">{{ e.name }} (×{{ e.quantity }})</option>
                        </select>
                        <span class="text-xl">+</span>
                        <select v-model="potion" class="h-10 rounded-md border border-input bg-background px-3 text-sm">
                            <option :value="null">Schlüpfpulver wählen …</option>
                            <option v-for="p in potions" :key="p.id" :value="p.id">{{ p.name }} (×{{ p.quantity }})</option>
                        </select>
                        <Button :disabled="!egg || !potion" @click="hatch">Ausbrüten</Button>
                    </div>
                </section>

                <p v-if="!pets.length" class="rounded-2xl border border-dashed p-10 text-center text-muted-foreground">Dein Stall ist noch leer.</p>
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                    <div v-for="pet in pets" :key="pet.id" class="flex flex-col items-center gap-2 rounded-2xl border bg-card p-4 text-center">
                        <PetArt :egg="pet.egg_key" :potion="pet.potion_key" :mount="pet.is_mount" :size="96" />
                        <p class="font-bold">{{ pet.name }}</p>
                        <p v-if="pet.is_mount" class="rounded-full bg-amber-100 px-2 text-xs font-bold text-amber-700">Reittier 🏇</p>
                        <template v-else>
                            <div class="h-2 w-full overflow-hidden rounded-full bg-muted">
                                <div class="h-full rounded-full bg-emerald-500 transition-all" :style="{ width: `${(pet.growth / pet.growth_max) * 100}%` }" />
                            </div>
                            <template v-if="feedFor === pet.id">
                                <button
                                    v-for="f in food"
                                    :key="f.id"
                                    class="w-full rounded-md border px-2 py-1 text-xs hover:bg-muted"
                                    @click="post('pets.feed', { pet: pet.id }, { food_id: f.id })"
                                >
                                    <span class="flex items-center gap-1"><ItemIcon :item="f" :size="20" /> {{ f.name }} (×{{ f.quantity }})</span>
                                </button>
                                <p v-if="!food.length" class="text-xs text-muted-foreground">Kein Futter – im Gold-Shop gibt es Äpfel.</p>
                            </template>
                            <button v-else class="rounded-md bg-emerald-600 px-3 py-1 text-xs font-bold text-white" @click="feedFor = pet.id">Füttern</button>
                        </template>
                    </div>
                </div>
            </template>
        </div>
    </AppLayout>
</template>
