<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Boxes, Hammer, House, Sprout, Trash2, Trophy } from 'lucide-vue-next';
import { reactive, ref } from 'vue';

interface Room {
    id: number;
    name: string;
    type: string;
    width: number;
    height: number;
    can_edit: boolean;
    version: number;
    style: { wall: string; floor: string };
    items: {
        id: number;
        item_id: number;
        key: string;
        name: string;
        icon: string;
        x: number;
        y: number;
        layer: string;
        rotation: number;
        mirrored: boolean;
        master: boolean;
    }[];
}

interface HousingData {
    family: { name: string };
    stage_name: string;
    rooms: Room[];
    clutter: { level: number; overdue: number };
    projects: {
        active: {
            id: number;
            key: string;
            name: string;
            icon: string;
            status: string;
            finishes_at: string | null;
            items: { key: string; name: string; icon: string; needed: number; have: number }[];
            gold: { needed: number; have: number };
            contributors: { name: string; count: number }[];
        }[];
        available: {
            key: string;
            name: string;
            icon: string;
            description: string | null;
            items: { key: string; name: string; icon: string; quantity: number }[];
            gold: number;
            hours: number;
        }[];
    };
    garden: {
        plants: { key: string; name: string; icon: string; cost: Record<string, number> }[];
        plots: {
            slot: number;
            plant_key: string | null;
            stage: 'empty' | 'thirsty' | 'growing' | 'ready';
            waterings: number;
            ready_at: string | null;
        }[];
    } | null;
    storage: { name: string; icon: string; quantity: number }[];
    guestbook: { id: number; kind: string; text: string; author: { id: number; name: string }; created_at: string; can_delete: boolean }[];
    trophies: {
        bosses: { key: string; name: string; icon: string; completed_at: string | null }[];
        master_items: { key: string; name: string; icon: string; owner_id: number }[];
        achievements: number;
    } | null;
    can_start_projects: boolean;
    can_write_text: boolean;
    styles: { walls: Record<string, string>; floors: Record<string, string> };
    presets: Record<string, string>;
    emojis: string[];
}

interface Furniture {
    id: number;
    key: string;
    name: string;
    icon: string;
    w: number;
    h: number;
    layers: string[];
    quantity: number;
    master_quantity: number;
}

const props = defineProps<{ home: HousingData; furniture: Furniture[]; visitor: boolean; alliedFamilies: { id: number; name: string }[] }>();
type Placement = {
    id?: number;
    item_id?: number;
    name: string;
    icon: string;
    x: number;
    y: number;
    layer: string;
    rotation: number;
    master: boolean;
};
const placementDrafts = reactive<Record<number, Placement[]>>(
    Object.fromEntries(
        props.home.rooms.map((room) => [
            room.id,
            room.items.map((item) => ({
                id: item.id,
                item_id: item.item_id,
                name: item.name,
                icon: item.icon,
                x: item.x,
                y: item.y,
                layer: item.layer,
                rotation: item.rotation,
                master: item.master,
            })),
        ]),
    ),
);
const selectedFurniture = ref<Record<number, number>>({});
const layoutInputs = ref<Record<number, { x: number; y: number; layer: string; master: boolean }>>({});
const gardenPlants = ref<Record<number, string>>({});
const contribution = ref<Record<number, { item: string; amount: number; from_storage: boolean }>>({});
const roomStyles = reactive<Record<number, { name: string; wall: string; floor: string }>>(
    Object.fromEntries(props.home.rooms.map((room) => [room.id, { name: room.name, wall: room.style.wall, floor: room.style.floor }])),
);
const showProjectForm = ref(false);
const rewardText = ref('');
const guestbookKind = ref<'text' | 'preset' | 'emoji'>('preset');
const guestbookBody = ref('');

const customizeRoom = (room: Room) => router.put(route('housing.rooms.customize', { room: room.id }), roomStyles[room.id], { preserveScroll: true });

const saveLayout = (room: Room) => {
    const items = placementDrafts[room.id].map((item) => ({
        ...(item.id ? { id: item.id } : { item_id: item.item_id }),
        x: item.x,
        y: item.y,
        layer: item.layer,
        rotation: item.rotation,
        master: item.master,
    }));
    router.put(
        route('housing.rooms.layout', { room: room.id }),
        { version: room.version, items },
        {
            preserveScroll: true,
            onSuccess: () => router.reload({ preserveScroll: true, preserveState: false }),
        },
    );
};

function addFurniture(room: Room) {
    const item = props.furniture.find((furniture) => furniture.id === selectedFurniture.value[room.id]);
    if (!item) return;
    const input = layoutInputs.value[room.id] ?? { x: 0, y: 0, layer: item.layers[0] ?? 'floor', master: false };
    input.layer = item.layers.includes(input.layer) ? input.layer : (item.layers[0] ?? 'floor');
    if (input.master && item.master_quantity < 1) input.master = false;
    placementDrafts[room.id].push({
        item_id: item.id,
        name: item.name,
        icon: item.icon,
        x: input.x,
        y: input.y,
        layer: input.layer,
        rotation: 0,
        master: input.master,
    });
    selectedFurniture.value[room.id] = 0;
}

function prepareLayout(room: Room) {
    const item = props.furniture.find((furniture) => furniture.id === selectedFurniture.value[room.id]);
    if (!item) return;
    const input = (layoutInputs.value[room.id] ??= { x: 0, y: 0, layer: item.layers[0] ?? 'floor', master: false });
    if (!item.layers.includes(input.layer)) input.layer = item.layers[0] ?? 'floor';
    if (item.master_quantity < 1) input.master = false;
}

function startProject(key: string) {
    router.post(
        route('housing.projects.start'),
        { project: key, reward_text: rewardText.value || null },
        { preserveScroll: true, onSuccess: () => (showProjectForm.value = false) },
    );
}

function contributeToProject(projectId: number) {
    const form = contribution.value[projectId];
    if (!form || form.amount < 1) return;
    router.post(route('housing.projects.contribute', { project: projectId }), form, { preserveScroll: true });
}

function gardenAction(slot: number, action: 'sow' | 'water' | 'harvest') {
    router.post(route('housing.garden.action', { slot, action }), action === 'sow' ? { plant: gardenPlants.value[slot] } : {}, {
        preserveScroll: true,
    });
}

function saveGuestbook() {
    if (!guestbookBody.value.trim()) return;
    const endpoint = props.visitor ? route('housing.visit.guestbook.store', { family: props.home.family.id }) : route('housing.guestbook.store');
    router.post(
        endpoint,
        { kind: guestbookKind.value, body: guestbookBody.value },
        {
            preserveScroll: true,
            onSuccess: () => (guestbookBody.value = ''),
        },
    );
}

const gardenStage = {
    empty: 'Frei',
    thirsty: 'Braucht Wasser',
    growing: 'Wächst',
    ready: 'Erntereif',
} as const;

const dateTime = (value: string) => new Intl.DateTimeFormat('de-DE', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value));

const plantName = (key: string | null) => props.home.garden?.plants.find((plant) => plant.key === key)?.name ?? key;
</script>

<template>
    <Head title="Familienhaus" />

    <AppLayout :breadcrumbs="[{ title: 'Familienhaus', href: '/housing' }]">
        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <header
                class="flex flex-wrap items-center gap-4 rounded-2xl border bg-gradient-to-br from-amber-50 to-orange-100 p-5 dark:from-amber-950/40 dark:to-orange-950/30"
            >
                <div class="grid size-14 place-items-center rounded-2xl bg-white/70 text-amber-700 dark:bg-black/20 dark:text-amber-300">
                    <House class="size-8" />
                </div>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold text-muted-foreground">{{ home.family.name }}</p>
                    <h1 class="text-2xl font-extrabold tracking-tight">Familienhaus</h1>
                    <p class="text-sm text-muted-foreground">{{ home.stage_name }}</p>
                    <p v-if="visitor" class="mt-1 text-xs font-semibold text-amber-800 dark:text-amber-200">Zu Besuch – ansehen und Grüße dalassen</p>
                </div>
                <div class="rounded-xl bg-white/70 px-4 py-2 text-sm dark:bg-black/20">
                    <span class="font-bold">Unordnung: {{ home.clutter.level }}/3</span>
                    <span class="ml-2 text-muted-foreground">({{ home.clutter.overdue }} überfällige Aufgaben)</span>
                </div>
            </header>

            <nav v-if="alliedFamilies.length" class="flex flex-wrap items-center gap-2" aria-label="Verbündete Familien besuchen">
                <span class="text-sm font-semibold text-muted-foreground">Besuchen:</span>
                <Link
                    v-for="family in alliedFamilies"
                    :key="family.id"
                    :href="route('housing.visit', { family: family.id })"
                    class="rounded-full border px-3 py-1.5 text-sm font-semibold hover:bg-muted"
                >
                    {{ family.name }}
                </Link>
                <Link v-if="visitor" :href="route('housing.index')" class="rounded-full border px-3 py-1.5 text-sm font-semibold hover:bg-muted">
                    Zurück zu meiner Familie
                </Link>
            </nav>

            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <Card v-for="room in home.rooms" :key="room.id">
                    <CardHeader class="pb-3">
                        <CardTitle class="flex items-center justify-between gap-3 text-lg">
                            <span>{{ room.name }}</span>
                            <span class="text-xs font-medium text-muted-foreground">{{ room.width }} × {{ room.height }}</span>
                        </CardTitle>
                        <CardDescription>{{ room.items.length }} Möbelstücke</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div v-if="room.items.length" class="flex flex-wrap gap-2">
                            <div v-for="item in room.items" :key="item.id" class="flex items-center gap-2 rounded-lg bg-muted px-3 py-2 text-sm">
                                <span aria-hidden="true">{{ item.icon }}</span>
                                <span>{{ item.name }}</span>
                            </div>
                        </div>
                        <p v-else class="text-sm text-muted-foreground">Das Zimmer ist noch leer.</p>
                        <template v-if="room.can_edit">
                            <form class="mt-4 grid gap-3 border-t pt-4" @submit.prevent="customizeRoom(room)">
                                <label class="grid gap-1 text-sm font-medium">
                                    Zimmername
                                    <Input v-model="roomStyles[room.id].name" maxlength="40" />
                                </label>
                                <div class="grid grid-cols-2 gap-2">
                                    <label class="grid gap-1 text-sm font-medium">
                                        Wand
                                        <select
                                            v-model="roomStyles[room.id].wall"
                                            class="h-9 rounded-md border border-input bg-background px-2 text-sm"
                                        >
                                            <option v-for="(label, key) in home.styles.walls" :key="key" :value="key">{{ label }}</option>
                                        </select>
                                    </label>
                                    <label class="grid gap-1 text-sm font-medium">
                                        Boden
                                        <select
                                            v-model="roomStyles[room.id].floor"
                                            class="h-9 rounded-md border border-input bg-background px-2 text-sm"
                                        >
                                            <option v-for="(label, key) in home.styles.floors" :key="key" :value="key">{{ label }}</option>
                                        </select>
                                    </label>
                                </div>
                                <Button type="submit" variant="outline" size="sm">Gestaltung speichern</Button>
                            </form>
                            <div class="mt-4 grid gap-3 border-t pt-4">
                                <h3 class="font-bold">Möbel einrichten</h3>
                                <div v-if="placementDrafts[room.id].length" class="grid gap-2">
                                    <div
                                        v-for="(item, index) in placementDrafts[room.id]"
                                        :key="item.id ?? `new-${index}`"
                                        class="flex flex-wrap items-center gap-2 rounded-lg bg-muted px-3 py-2 text-sm"
                                    >
                                        <span>{{ item.icon }} {{ item.name }}</span>
                                        <label class="flex items-center gap-1"
                                            >X
                                            <input
                                                v-model.number="item.x"
                                                type="number"
                                                min="0"
                                                :max="room.width - 1"
                                                class="h-8 w-16 rounded border bg-background px-2"
                                        /></label>
                                        <label class="flex items-center gap-1"
                                            >Y
                                            <input
                                                v-model.number="item.y"
                                                type="number"
                                                min="0"
                                                :max="room.height - 1"
                                                class="h-8 w-16 rounded border bg-background px-2"
                                        /></label>
                                        <select v-model="item.layer" class="h-8 rounded border bg-background px-2" aria-label="Möbelebene">
                                            <option value="floor">Boden</option>
                                            <option value="wall">Wand</option>
                                            <option value="ceiling">Decke</option>
                                        </select>
                                        <select v-model.number="item.rotation" class="h-8 rounded border bg-background px-2" aria-label="Drehung">
                                            <option :value="0">0°</option>
                                            <option :value="90">90°</option>
                                            <option :value="180">180°</option>
                                            <option :value="270">270°</option>
                                        </select>
                                        <button
                                            type="button"
                                            class="ml-auto text-destructive"
                                            :aria-label="`${item.name} entfernen`"
                                            @click="placementDrafts[room.id].splice(index, 1)"
                                        >
                                            <Trash2 class="size-4" />
                                        </button>
                                    </div>
                                </div>
                                <p v-else class="text-sm text-muted-foreground">Noch keine Möbel aufgestellt.</p>
                                <div v-if="furniture.length" class="grid gap-2 sm:grid-cols-2">
                                    <select
                                        v-model.number="selectedFurniture[room.id]"
                                        class="h-9 rounded-md border border-input bg-background px-2 text-sm"
                                        @change="prepareLayout(room)"
                                    >
                                        <option :value="0">Möbel aus dem Inventar wählen</option>
                                        <option
                                            v-for="item in furniture"
                                            :key="item.id"
                                            :value="item.id"
                                            :disabled="item.quantity < 1 && item.master_quantity < 1"
                                        >
                                            {{ item.icon }} {{ item.name }} ({{ item.quantity + item.master_quantity }})
                                        </option>
                                    </select>
                                    <div class="flex gap-2">
                                        <label class="flex items-center gap-1 text-xs">
                                            X
                                            <input
                                                v-model.number="(layoutInputs[room.id] ??= { x: 0, y: 0, layer: 'floor', master: false }).x"
                                                type="number"
                                                min="0"
                                                :max="room.width - 1"
                                                class="h-9 w-16 rounded border bg-background px-2"
                                            />
                                        </label>
                                        <label class="flex items-center gap-1 text-xs">
                                            Y
                                            <input
                                                v-model.number="(layoutInputs[room.id] ??= { x: 0, y: 0, layer: 'floor', master: false }).y"
                                                type="number"
                                                min="0"
                                                :max="room.height - 1"
                                                class="h-9 w-16 rounded border bg-background px-2"
                                            />
                                        </label>
                                        <label class="flex items-center gap-1 text-xs">
                                            Ebene
                                            <select
                                                v-model="(layoutInputs[room.id] ??= { x: 0, y: 0, layer: 'floor', master: false }).layer"
                                                class="h-9 rounded border bg-background px-2"
                                            >
                                                <option
                                                    v-for="layer in furniture.find((item) => item.id === selectedFurniture[room.id])?.layers ?? [
                                                        'floor',
                                                    ]"
                                                    :key="layer"
                                                    :value="layer"
                                                >
                                                    {{ layer === 'floor' ? 'Boden' : layer === 'wall' ? 'Wand' : 'Decke' }}
                                                </option>
                                            </select>
                                        </label>
                                        <label
                                            v-if="(furniture.find((item) => item.id === selectedFurniture[room.id])?.master_quantity ?? 0) > 0"
                                            class="flex items-center gap-1 text-xs"
                                        >
                                            <input
                                                v-model="(layoutInputs[room.id] ??= { x: 0, y: 0, layer: 'floor', master: false }).master"
                                                type="checkbox"
                                                class="size-4"
                                            />
                                            Meisterhaft
                                        </label>
                                        <Button type="button" variant="outline" size="sm" @click="addFurniture(room)">Hinzufügen</Button>
                                    </div>
                                </div>
                                <Button type="button" size="sm" @click="saveLayout(room)">Einrichtung speichern</Button>
                                <p class="text-xs text-muted-foreground">
                                    Rasterpositionen starten links oben. Entfernte Möbel gehen an die Person zurück, die sie aufgestellt hat.
                                </p>
                            </div>
                        </template>
                    </CardContent>
                </Card>
            </section>

            <section class="grid gap-4 lg:grid-cols-2">
                <Card v-if="!visitor">
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2"><Hammer class="size-5" /> Bauprojekte</CardTitle>
                        <CardDescription>Der aktuelle Stand gemeinsamer Bauvorhaben.</CardDescription>
                    </CardHeader>
                    <CardContent class="grid gap-4">
                        <div v-if="home.can_start_projects && home.projects.available.length">
                            <Button variant="outline" size="sm" @click="showProjectForm = !showProjectForm">
                                {{ showProjectForm ? 'Projektliste schließen' : 'Bauprojekt starten' }}
                            </Button>
                            <div v-if="showProjectForm" class="mt-3 grid gap-3 rounded-xl border p-3">
                                <Input v-model="rewardText" maxlength="120" placeholder="Gemeinsame Belohnung (optional)" />
                                <div
                                    v-for="project in home.projects.available"
                                    :key="project.key"
                                    class="flex flex-wrap items-center gap-3 rounded-lg bg-muted p-3"
                                >
                                    <span class="text-xl">{{ project.icon }}</span>
                                    <div class="min-w-0 flex-1">
                                        <p class="font-bold">{{ project.name }}</p>
                                        <p v-if="project.description" class="text-xs text-muted-foreground">{{ project.description }}</p>
                                        <p class="mt-1 text-xs text-muted-foreground">
                                            {{ project.items.map((item) => `${item.icon} ${item.quantity}× ${item.name}`).join(' · ') }}
                                            <span v-if="project.gold"> · {{ project.gold }} Gold</span>
                                        </p>
                                    </div>
                                    <Button size="sm" @click="startProject(project.key)">Starten</Button>
                                </div>
                            </div>
                        </div>
                        <div v-for="project in home.projects.active" :key="project.id" class="rounded-xl border p-3">
                            <div class="mb-3 flex items-center gap-2 font-bold">
                                <span>{{ project.icon }}</span
                                >{{ project.name }}
                            </div>
                            <div v-for="item in project.items" :key="item.name" class="mb-2 text-sm">
                                <div class="mb-1 flex justify-between gap-2">
                                    <span>{{ item.name }}</span
                                    ><span class="text-muted-foreground">{{ item.have }}/{{ item.needed }}</span>
                                </div>
                                <div class="h-2 overflow-hidden rounded-full bg-muted">
                                    <div
                                        class="h-full rounded-full bg-amber-500"
                                        :style="{ width: `${Math.min(100, (item.have / item.needed) * 100)}%` }"
                                    />
                                </div>
                            </div>
                            <div v-if="project.gold.needed" class="mb-2 text-sm">
                                <div class="mb-1 flex justify-between gap-2">
                                    <span>Gold</span><span class="text-muted-foreground">{{ project.gold.have }}/{{ project.gold.needed }}</span>
                                </div>
                                <div class="h-2 overflow-hidden rounded-full bg-muted">
                                    <div
                                        class="h-full rounded-full bg-amber-500"
                                        :style="{ width: `${Math.min(100, (project.gold.have / project.gold.needed) * 100)}%` }"
                                    />
                                </div>
                            </div>
                            <p v-if="project.finishes_at" class="mt-2 text-xs text-muted-foreground">
                                Fertigstellung: {{ dateTime(project.finishes_at) }}
                            </p>
                            <p v-if="project.contributors.length" class="mt-2 text-xs text-muted-foreground">
                                Beiträge: {{ project.contributors.map((entry) => `${entry.name} (${entry.count})`).join(', ') }}
                            </p>
                            <div v-if="project.status === 'collecting'" class="mt-3 flex flex-wrap items-center gap-2 border-t pt-3">
                                <select
                                    v-model="(contribution[project.id] ??= { item: '', amount: 1, from_storage: false }).item"
                                    class="h-9 min-w-40 rounded-md border border-input bg-background px-2 text-sm"
                                >
                                    <option value="">Gold beitragen</option>
                                    <option
                                        v-for="item in project.items.filter((entry) => entry.have < entry.needed)"
                                        :key="item.key"
                                        :value="item.key"
                                    >
                                        {{ item.icon }} {{ item.name }} (noch {{ item.needed - item.have }})
                                    </option>
                                </select>
                                <Input
                                    v-model.number="(contribution[project.id] ??= { item: '', amount: 1, from_storage: false }).amount"
                                    type="number"
                                    min="1"
                                    max="10000"
                                    class="w-24"
                                />
                                <label v-if="contribution[project.id]?.item" class="flex items-center gap-1 text-xs">
                                    <input v-model="contribution[project.id].from_storage" type="checkbox" class="size-4" /> aus Lager
                                </label>
                                <Button size="sm" @click="contributeToProject(project.id)">Beitragen</Button>
                                <Button
                                    v-if="home.can_start_projects"
                                    variant="destructive"
                                    size="sm"
                                    @click="router.delete(route('housing.projects.cancel', { project: project.id }), { preserveScroll: true })"
                                >
                                    Abbrechen
                                </Button>
                            </div>
                        </div>
                        <p v-if="!home.projects.active.length" class="text-sm text-muted-foreground">Zurzeit gibt es keine laufenden Bauprojekte.</p>
                    </CardContent>
                </Card>

                <Card v-if="!visitor">
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2"><Sprout class="size-5" /> Garten</CardTitle>
                        <CardDescription v-if="home.garden">Der Zustand eurer Beete.</CardDescription>
                        <CardDescription v-else>Ein Garten kann als Bauprojekt freigeschaltet werden.</CardDescription>
                    </CardHeader>
                    <CardContent v-if="home.garden?.plots.length" class="grid grid-cols-2 gap-3">
                        <div v-for="plot in home.garden.plots" :key="plot.slot" class="grid content-start gap-2 rounded-xl border p-3">
                            <p class="font-bold">Beet {{ plot.slot }}</p>
                            <p class="text-sm text-muted-foreground">{{ plantName(plot.plant_key) ?? 'Noch nicht bepflanzt' }}</p>
                            <p class="text-sm">{{ gardenStage[plot.stage] }}</p>
                            <p v-if="plot.stage === 'growing' && plot.ready_at" class="text-xs text-muted-foreground">
                                Reif etwa {{ dateTime(plot.ready_at) }}
                            </p>
                            <template v-if="plot.stage === 'empty'">
                                <select v-model="gardenPlants[plot.slot]" class="h-9 rounded-md border border-input bg-background px-2 text-sm">
                                    <option value="">Saatgut wählen</option>
                                    <option v-for="plant in home.garden.plants" :key="plant.key" :value="plant.key">
                                        {{ plant.icon }} {{ plant.name }} ({{
                                            Object.entries(plant.cost)
                                                .map(([key, count]) => `${count} ${key}`)
                                                .join(', ')
                                        }})
                                    </option>
                                </select>
                                <Button size="sm" :disabled="!gardenPlants[plot.slot]" @click="gardenAction(plot.slot, 'sow')">Aussäen</Button>
                            </template>
                            <Button v-else-if="plot.stage === 'thirsty'" size="sm" @click="gardenAction(plot.slot, 'water')">Gießen</Button>
                            <Button v-else-if="plot.stage === 'ready'" size="sm" @click="gardenAction(plot.slot, 'harvest')">Ernten</Button>
                        </div>
                    </CardContent>
                    <CardContent v-else>
                        <p class="text-sm text-muted-foreground">Sobald der Garten gebaut ist, erscheinen eure Beete hier.</p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Gästebuch</CardTitle>
                        <CardDescription>{{
                            visitor ? 'Hinterlasse einen Gruß bei dieser Familie.' : 'Haltet Grüße und schöne Familienmomente fest.'
                        }}</CardDescription>
                    </CardHeader>
                    <CardContent class="grid gap-4">
                        <form class="grid gap-2 sm:grid-cols-[9rem_1fr_auto]" @submit.prevent="saveGuestbook">
                            <select v-model="guestbookKind" class="h-10 rounded-md border border-input bg-background px-2 text-sm">
                                <option value="preset">Gruß</option>
                                <option value="emoji">Emoji</option>
                                <option v-if="home.can_write_text" value="text">Text</option>
                            </select>
                            <select
                                v-if="guestbookKind === 'preset'"
                                v-model="guestbookBody"
                                required
                                class="h-10 rounded-md border border-input bg-background px-2 text-sm"
                            >
                                <option value="">Gruß wählen</option>
                                <option v-for="(text, key) in home.presets" :key="key" :value="key">{{ text }}</option>
                            </select>
                            <select
                                v-else-if="guestbookKind === 'emoji'"
                                v-model="guestbookBody"
                                required
                                class="h-10 rounded-md border border-input bg-background px-2 text-sm"
                            >
                                <option value="">Emoji wählen</option>
                                <option v-for="emoji in home.emojis" :key="emoji" :value="emoji">{{ emoji }}</option>
                            </select>
                            <Input v-else v-model="guestbookBody" maxlength="300" placeholder="Dein Eintrag …" required />
                            <Button type="submit">Eintragen</Button>
                        </form>
                        <div v-if="home.guestbook.length" class="grid gap-2">
                            <div v-for="entry in home.guestbook" :key="entry.id" class="flex items-start gap-3 rounded-lg bg-muted p-3">
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm">{{ entry.text }}</p>
                                    <p class="mt-1 text-xs text-muted-foreground">{{ entry.author.name }} · {{ dateTime(entry.created_at) }}</p>
                                </div>
                                <button
                                    v-if="entry.can_delete"
                                    type="button"
                                    class="text-muted-foreground hover:text-destructive"
                                    :aria-label="`Eintrag von ${entry.author.name} löschen`"
                                    @click="router.delete(route('housing.guestbook.destroy', { entry: entry.id }), { preserveScroll: true })"
                                >
                                    <Trash2 class="size-4" />
                                </button>
                            </div>
                        </div>
                        <p v-else class="text-sm text-muted-foreground">Im Gästebuch steht noch nichts.</p>
                    </CardContent>
                </Card>

                <Card v-if="!visitor">
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2"><Boxes class="size-5" /> Familienlager</CardTitle>
                        <CardDescription>Gemeinsam eingelagerte Materialien.</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div v-if="home.storage.length" class="flex flex-wrap gap-2">
                            <span v-for="item in home.storage" :key="item.name" class="rounded-lg bg-muted px-3 py-2 text-sm">
                                {{ item.icon }} {{ item.name }} × {{ item.quantity }}
                            </span>
                        </div>
                        <p v-else class="text-sm text-muted-foreground">Im Familienlager liegt noch nichts.</p>
                    </CardContent>
                </Card>

                <Card v-if="home.trophies">
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2"><Trophy class="size-5" /> Trophäenhalle</CardTitle>
                        <CardDescription>{{ home.trophies.achievements }} Familien-Erfolge</CardDescription>
                    </CardHeader>
                    <CardContent class="grid gap-4">
                        <section v-if="home.trophies.bosses.length">
                            <h3 class="mb-2 text-sm font-bold">Besiegte Bosse</h3>
                            <div class="flex flex-wrap gap-2">
                                <span v-for="boss in home.trophies.bosses" :key="boss.key" class="rounded-lg bg-muted px-3 py-2 text-sm">
                                    {{ boss.icon }} {{ boss.name }}
                                </span>
                            </div>
                        </section>
                        <section v-if="home.trophies.master_items.length">
                            <h3 class="mb-2 text-sm font-bold">Meisterstücke</h3>
                            <div class="flex flex-wrap gap-2">
                                <span
                                    v-for="item in home.trophies.master_items"
                                    :key="`${item.key}-${item.owner_id}`"
                                    class="rounded-lg bg-muted px-3 py-2 text-sm"
                                >
                                    {{ item.icon }} {{ item.name }}
                                </span>
                            </div>
                        </section>
                        <p v-if="!home.trophies.bosses.length && !home.trophies.master_items.length" class="text-sm text-muted-foreground">
                            Die ersten Trophäen warten auf euch.
                        </p>
                    </CardContent>
                </Card>
            </section>
        </div>
    </AppLayout>
</template>
