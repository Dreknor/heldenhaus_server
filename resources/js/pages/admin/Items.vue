<script setup lang="ts">
import ItemIcon from '@/components/game/ItemIcon.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { Dices, Pencil, Plus, Search } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Item {
    id: number;
    key: string;
    name: string;
    description: string | null;
    type: string;
    slot: string | null;
    rarity: string;
    icon: string;
    stats: Record<string, number> | null;
    price_gold: number | null;
    drop_weight: number;
    drop_categories: string[] | null;
    min_level: number;
    active: boolean;
    owned: number;
}

const props = defineProps<{
    items: Item[];
    settings: { base_chance: number; daily_limit: number; per_bonus_per_point: number };
    types: Record<string, string>;
    slots: Record<string, string>;
    rarities: Record<string, string>;
    categories: Record<string, string>;
    difficulties: Record<string, number>;
}>();

// ---------- Filter ----------
const search = ref('');
const typeFilter = ref('');
const previewCategory = ref('household');
const previewLevel = ref(10);

const filtered = computed(() =>
    props.items.filter(
        (i) =>
            (!typeFilter.value || i.type === typeFilter.value) &&
            (!search.value || `${i.name} ${i.key}`.toLowerCase().includes(search.value.toLowerCase())),
    ),
);

// ---------- Drop-Einstellungen (live berechnet) ----------
const settingsForm = useForm({ ...props.settings });

function chanceFor(difficulty: number): number {
    const factor = 0.5 + Math.min(2, difficulty) / 2;
    return Math.min(0.95, settingsForm.base_chance * factor);
}

const pool = computed(() =>
    props.items.filter(
        (i) =>
            i.active &&
            i.drop_weight > 0 &&
            i.min_level <= previewLevel.value &&
            (!i.drop_categories?.length || i.drop_categories.includes(previewCategory.value)),
    ),
);
const poolWeight = computed(() => pool.value.reduce((sum, i) => sum + i.drop_weight, 0));

/** Wahrscheinlichkeit, dass genau dieses Item bei einer leichten Aufgabe fällt. */
function itemChance(item: Item): number | null {
    if (!pool.value.includes(item) || poolWeight.value === 0) return null;
    return chanceFor(1) * (item.drop_weight / poolWeight.value);
}

const pct = (v: number) => `${(v * 100).toFixed(v < 0.01 ? 2 : 1).replace('.', ',')} %`;

const rarityTotals = computed(() => {
    const totals: Record<string, number> = {};
    for (const item of pool.value) totals[item.rarity] = (totals[item.rarity] ?? 0) + item.drop_weight / Math.max(1, poolWeight.value);
    return totals;
});

// Gewicht direkt in der Tabelle ändern
function saveWeight(item: Item, value: number) {
    if (value === item.drop_weight || value < 0) return;
    router.put(route('admin.items.update', { item: item.id }), { ...toPayload(item), drop_weight: value }, { preserveScroll: true });
}

// ---------- Bearbeiten ----------
const open = ref(false);
const editing = ref<Item | null>(null);

const empty = () => ({
    key: '',
    name: '',
    description: '',
    type: 'material',
    slot: '' as string,
    rarity: 'common',
    icon: '📦',
    stats: { str: 0, int: 0, con: 0, per: 0, heal: 0, mana: 0 } as Record<string, number>,
    price_gold: '' as number | '',
    drop_weight: 10,
    drop_categories: [] as string[],
    min_level: 1,
    active: true as boolean,
});

const form = useForm(empty());

function toPayload(item: Item) {
    return {
        key: item.key,
        name: item.name,
        description: item.description,
        type: item.type,
        slot: item.slot,
        rarity: item.rarity,
        icon: item.icon,
        stats: item.stats ?? {},
        price_gold: item.price_gold,
        drop_weight: item.drop_weight,
        drop_categories: item.drop_categories ?? [],
        min_level: item.min_level,
        active: item.active,
    };
}

function edit(item: Item | null) {
    editing.value = item;
    const base = empty();
    form.defaults(
        item
            ? {
                  ...base,
                  ...toPayload(item),
                  description: item.description ?? '',
                  slot: item.slot ?? '',
                  price_gold: item.price_gold ?? '',
                  stats: { ...base.stats, ...(item.stats ?? {}) },
              }
            : base,
    );
    form.reset();
    form.clearErrors();
    open.value = true;
}

function save() {
    form.transform((d) => ({ ...d, slot: d.slot || null, price_gold: d.price_gold === '' ? null : d.price_gold, description: d.description || null }));
    const options = { preserveScroll: true, onSuccess: () => (open.value = false) };
    if (editing.value) form.put(route('admin.items.update', { item: editing.value.id }), options);
    else form.post(route('admin.items.store'), options);
}

function remove() {
    if (!editing.value || !confirm(`„${editing.value.name}“ löschen? Items im Besitz von Spielern werden nur deaktiviert.`)) return;
    router.delete(route('admin.items.destroy', { item: editing.value.id }), { preserveScroll: true, onSuccess: () => (open.value = false) });
}

function toggleCategory(key: string) {
    form.drop_categories = form.drop_categories.includes(key) ? form.drop_categories.filter((c) => c !== key) : [...form.drop_categories, key];
}

const rarityClass: Record<string, string> = {
    common: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200',
    uncommon: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
    rare: 'bg-sky-100 text-sky-700 dark:bg-sky-900/40 dark:text-sky-300',
    epic: 'bg-violet-100 text-violet-700 dark:bg-violet-900/40 dark:text-violet-300',
    legendary: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
};
const statLabels: Record<string, string> = { str: 'Kraft', int: 'Köpfchen', con: 'Ausdauer', per: 'Spürsinn' };
const selectClass = 'h-9 rounded-md border border-input bg-background px-3 text-sm';
const chip = (active: boolean) =>
    `rounded-lg border px-2.5 py-1 text-xs font-semibold transition ${active ? 'border-primary bg-primary text-primary-foreground' : 'hover:bg-muted'}`;
</script>

<template>
    <Head title="Items & Drops" />

    <AppLayout :breadcrumbs="[{ title: 'Items & Drops', href: '/admin/items' }]">
        <div class="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="flex-1 text-2xl font-extrabold tracking-tight">Items & Drops</h1>
                <Button @click="edit(null)"><Plus class="size-4" /> Neues Item</Button>
            </div>

            <!-- Drop-Einstellungen -->
            <section class="grid gap-6 rounded-2xl border bg-card p-5 xl:grid-cols-2">
                <form class="grid gap-3" @submit.prevent="settingsForm.put(route('admin.drop-settings.update'), { preserveScroll: true })">
                    <h2 class="flex items-center gap-2 font-extrabold"><Dices class="size-5" /> Drop-Einstellungen</h2>
                    <div class="grid gap-1.5">
                        <Label for="base">Grundchance pro leichter Aufgabe: {{ pct(settingsForm.base_chance) }}</Label>
                        <input id="base" v-model.number="settingsForm.base_chance" type="range" min="0" max="0.95" step="0.01" class="accent-orange-500" />
                        <InputError :message="settingsForm.errors.base_chance" />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="grid gap-1.5">
                            <Label for="limit">Max. Funde pro Tag</Label>
                            <Input id="limit" v-model.number="settingsForm.daily_limit" type="number" min="0" max="100" />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="perb">Bonus je Spürsinn-Punkt</Label>
                            <Input id="perb" v-model.number="settingsForm.per_bonus_per_point" type="number" min="0" max="0.2" step="0.005" />
                        </div>
                    </div>
                    <div><Button type="submit" :disabled="settingsForm.processing">Speichern</Button></div>
                </form>

                <div class="grid content-start gap-2 text-sm">
                    <p class="font-semibold">Chance auf einen Fund je Schwierigkeit</p>
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                        <div v-for="(value, key) in difficulties" :key="key" class="rounded-lg bg-muted p-2 text-center">
                            <p class="text-xs text-muted-foreground">{{ { trivial: 'Winzig', easy: 'Leicht', medium: 'Mittel', hard: 'Schwer' }[key] }}</p>
                            <p class="font-bold">{{ pct(chanceFor(value)) }}</p>
                        </div>
                    </div>
                    <p class="mt-2 font-semibold">Vorschau-Pool</p>
                    <div class="flex flex-wrap items-center gap-2">
                        <select v-model="previewCategory" :class="selectClass">
                            <option v-for="(label, key) in categories" :key="key" :value="key">{{ label }}</option>
                        </select>
                        <span>bei Level</span>
                        <Input v-model.number="previewLevel" type="number" min="1" class="w-20" />
                    </div>
                    <div class="flex flex-wrap gap-1.5">
                        <span v-for="(share, rarity) in rarityTotals" :key="rarity" class="rounded-full px-2 py-0.5 text-xs font-bold" :class="rarityClass[rarity]">
                            {{ rarities[rarity] }} {{ pct(share) }}
                        </span>
                    </div>
                    <p class="text-xs text-muted-foreground">{{ pool.length }} mögliche Funde, Gesamtgewicht {{ poolWeight }}. Die Werte unten gelten für eine leichte Aufgabe in dieser Kategorie.</p>
                </div>
            </section>

            <!-- Filter -->
            <div class="flex flex-wrap items-center gap-2">
                <div class="relative">
                    <Search class="absolute left-2.5 top-2.5 size-4 text-muted-foreground" />
                    <Input v-model="search" placeholder="Suchen …" class="w-56 pl-8" />
                </div>
                <button :class="chip(typeFilter === '')" @click="typeFilter = ''">Alle</button>
                <button v-for="(label, key) in types" :key="key" :class="chip(typeFilter === key)" @click="typeFilter = key">{{ label }}</button>
            </div>

            <!-- Tabelle -->
            <div class="overflow-x-auto rounded-xl border bg-card">
                <table class="w-full text-sm">
                    <thead class="bg-muted/60 text-left text-xs uppercase text-muted-foreground">
                        <tr>
                            <th class="p-3">Item</th>
                            <th class="p-3">Typ</th>
                            <th class="p-3">Seltenheit</th>
                            <th class="p-3">Preis</th>
                            <th class="p-3">Drop-Gewicht</th>
                            <th class="p-3">Chance</th>
                            <th class="p-3">Besitz</th>
                            <th class="p-3" />
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="item in filtered" :key="item.id" :class="item.active ? '' : 'opacity-50'">
                            <td class="p-3">
                                <div class="flex items-center gap-2">
                                    <ItemIcon :item="item" :size="40" framed />
                                    <div>
                                        <p class="font-semibold">{{ item.name }}</p>
                                        <p class="font-mono text-xs text-muted-foreground">
                                            {{ item.key }}<template v-if="item.min_level > 1"> · ab Lvl {{ item.min_level }}</template>
                                            <template v-if="item.drop_categories?.length"> · nur {{ item.drop_categories.map((c) => categories[c]).join(', ') }}</template>
                                        </p>
                                    </div>
                                </div>
                            </td>
                            <td class="p-3">{{ types[item.type] }}<template v-if="item.slot"> ({{ slots[item.slot] }})</template></td>
                            <td class="p-3">
                                <span class="rounded-full px-2 py-0.5 text-xs font-bold" :class="rarityClass[item.rarity]">{{ rarities[item.rarity] }}</span>
                            </td>
                            <td class="p-3">{{ item.price_gold ?? '–' }}</td>
                            <td class="p-3">
                                <input
                                    type="number"
                                    min="0"
                                    :value="item.drop_weight"
                                    class="h-8 w-20 rounded-md border border-input bg-background px-2"
                                    @change="saveWeight(item, Number(($event.target as HTMLInputElement).value))"
                                />
                            </td>
                            <td class="p-3 font-semibold tabular-nums">{{ itemChance(item) === null ? '–' : pct(itemChance(item)!) }}</td>
                            <td class="p-3 tabular-nums">{{ item.owned }}</td>
                            <td class="p-3 text-right">
                                <button class="rounded-md p-1.5 hover:bg-muted" aria-label="Bearbeiten" @click="edit(item)"><Pencil class="size-4" /></button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Item-Dialog -->
        <Dialog v-model:open="open">
            <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>{{ editing ? `Item bearbeiten: ${editing.name}` : 'Neues Item' }}</DialogTitle>
                </DialogHeader>
                <form class="grid gap-3" @submit.prevent="save">
                    <div class="grid grid-cols-[4rem_1fr_1fr] gap-3">
                        <div class="grid gap-1.5">
                            <Label for="i-icon">Icon</Label>
                            <Input id="i-icon" v-model="form.icon" class="text-center text-xl" />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="i-name">Name</Label>
                            <Input id="i-name" v-model="form.name" required />
                            <InputError :message="form.errors.name" />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="i-key">Schlüssel</Label>
                            <Input id="i-key" v-model="form.key" required class="font-mono" placeholder="egg_dragon" />
                            <InputError :message="form.errors.key" />
                        </div>
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="i-desc">Beschreibung</Label>
                        <Input id="i-desc" v-model="form.description" />
                    </div>
                    <div class="grid grid-cols-3 gap-3">
                        <div class="grid gap-1.5">
                            <Label for="i-type">Typ</Label>
                            <select id="i-type" v-model="form.type" :class="selectClass">
                                <option v-for="(label, key) in types" :key="key" :value="key">{{ label }}</option>
                            </select>
                        </div>
                        <div v-if="form.type === 'equipment'" class="grid gap-1.5">
                            <Label for="i-slot">Slot</Label>
                            <select id="i-slot" v-model="form.slot" :class="selectClass">
                                <option value="">–</option>
                                <option v-for="(label, key) in slots" :key="key" :value="key">{{ label }}</option>
                            </select>
                            <InputError :message="form.errors.slot" />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="i-rarity">Seltenheit</Label>
                            <select id="i-rarity" v-model="form.rarity" :class="selectClass">
                                <option v-for="(label, key) in rarities" :key="key" :value="key">{{ label }}</option>
                            </select>
                        </div>
                    </div>

                    <div v-if="form.type === 'equipment'" class="grid gap-1.5">
                        <Label>Attribut-Boni</Label>
                        <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                            <div v-for="(label, key) in statLabels" :key="key" class="grid gap-1">
                                <span class="text-xs text-muted-foreground">{{ label }}</span>
                                <Input v-model.number="form.stats[key]" type="number" min="0" max="50" />
                            </div>
                        </div>
                    </div>

                    <div v-if="form.type === 'potion'" class="grid grid-cols-2 gap-2">
                        <div class="grid gap-1">
                            <Label for="i-heal">Heilt HP</Label>
                            <Input id="i-heal" v-model.number="form.stats.heal" type="number" min="0" max="50" />
                        </div>
                        <div class="grid gap-1">
                            <Label for="i-mana">Gibt Mana</Label>
                            <Input id="i-mana" v-model.number="form.stats.mana" type="number" min="0" max="50" />
                        </div>
                    </div>

                    <div class="grid grid-cols-3 gap-3">
                        <div class="grid gap-1.5">
                            <Label for="i-price">Shop-Preis (Gold)</Label>
                            <Input id="i-price" v-model.number="form.price_gold" type="number" min="0" placeholder="nicht im Shop" />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="i-weight">Drop-Gewicht</Label>
                            <Input id="i-weight" v-model.number="form.drop_weight" type="number" min="0" />
                            <InputError :message="form.errors.drop_weight" />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="i-level">Ab Level</Label>
                            <Input id="i-level" v-model.number="form.min_level" type="number" min="1" />
                        </div>
                    </div>
                    <p class="-mt-1 text-xs text-muted-foreground">Gewicht 0 = fällt nie. Je höher im Verhältnis zu den anderen Items, desto häufiger.</p>

                    <div class="grid gap-1.5">
                        <Label>Fällt nur bei Aufgaben dieser Kategorien (leer = alle)</Label>
                        <div class="flex flex-wrap gap-1.5">
                            <button v-for="(label, key) in categories" :key="key" type="button" :class="chip(form.drop_categories.includes(key))" @click="toggleCategory(key)">
                                {{ label }}
                            </button>
                        </div>
                    </div>

                    <label class="flex items-center gap-2 text-sm"><input v-model="form.active" type="checkbox" class="size-4" /> Aktiv</label>

                    <DialogFooter class="gap-2 sm:justify-between">
                        <Button v-if="editing" type="button" variant="destructive" @click="remove">Löschen</Button>
                        <span v-else />
                        <Button type="submit" :disabled="form.processing">Speichern</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
