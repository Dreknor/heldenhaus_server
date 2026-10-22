<script setup lang="ts">
import FurnitureArt from '@/components/game/FurnitureArt.vue';
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
        w: number;
        h: number;
        layer: string;
        rotation: number;
        mirrored: boolean;
        master: boolean;
    }[];
}

interface HousingData {
    family: { id: number; name: string };
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
    key: string;
    name: string;
    icon: string;
    w: number;
    h: number;
    x: number;
    y: number;
    layer: string;
    rotation: number;
    mirrored: boolean;
    master: boolean;
};
const placementDrafts = reactive<Record<number, Placement[]>>(
    Object.fromEntries(
        props.home.rooms.map((room) => [
            room.id,
            room.items.map((item) => ({
                id: item.id,
                item_id: item.item_id,
                key: item.key,
                name: item.name,
                icon: item.icon,
                w: item.w,
                h: item.h,
                x: item.x,
                y: item.y,
                layer: item.layer,
                rotation: item.rotation,
                mirrored: item.mirrored,
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

const wallColors: Record<string, string> = {
    stone: '#a99a85',
    wood: '#a56842',
    stars: '#48416f',
    stripes: '#b77e70',
    pastel: '#e7bfca',
    castle: '#7c8491',
};
const floorColors: Record<string, string> = {
    planks: '#a97850',
    stone: '#858c91',
    carpet: '#9c5a64',
    grass: '#6e9b57',
    tiles: '#8eb8b3',
};

function roomCellClick(event: MouseEvent, room: Room) {
    if (!room.can_edit || !selectedFurniture.value[room.id]) return;
    const bounds = (event.currentTarget as HTMLElement).getBoundingClientRect();
    const x = Math.floor(((event.clientX - bounds.left) / bounds.width) * room.width);
    const furniture = props.furniture.find((item) => item.id === selectedFurniture.value[room.id]);
    if (!furniture) return;
    const input = (layoutInputs.value[room.id] ??= { x: 0, y: 0, layer: 'floor', master: false });
    const yStart = input.layer === 'floor' ? 0.43 : input.layer === 'wall' ? 0.05 : 0;
    const yRange = input.layer === 'floor' ? 0.57 : input.layer === 'wall' ? 0.3 : 1;
    const y = Math.floor(Math.max(0, (event.clientY - bounds.top - bounds.height * yStart) / (bounds.height * yRange)) * room.height);
    input.x = Math.max(0, Math.min(room.width - furniture.w, x));
    input.y = Math.max(0, Math.min(room.height - furniture.h, y));
    prepareLayout(room);
}

function placementFootprint(item: Placement) {
    return item.rotation % 180 === 0 ? { w: item.w, h: item.h } : { w: item.h, h: item.w };
}

function clampPlacement(item: Placement, room: Room) {
    const { w, h } = placementFootprint(item);
    item.x = Math.max(0, Math.min(room.width - w, item.x || 0));
    item.y = Math.max(0, Math.min(room.height - h, item.y || 0));
}

function placedItemStyle(room: Room, item: Placement): Record<string, string | number> {
    const footprint = placementFootprint(item);
    const top = item.layer === 'floor' ? 43 + (item.y / room.height) * 55 : item.layer === 'wall' ? 5 + (item.y / room.height) * 30 : 2;

    return {
        left: `${(item.x / room.width) * 100}%`,
        top: `${top}%`,
        width: `${(Math.min(footprint.w, room.width - item.x) / room.width) * 100}%`,
        height: `${(Math.min(footprint.h, room.height - item.y) / room.height) * (item.layer === 'floor' ? 55 : 30)}%`,
        zIndex: item.layer === 'floor' ? 4 : item.layer === 'wall' ? 6 : 7,
        transform: `scaleX(${item.mirrored ? -1 : 1})`,
    };
}

function previewStyle(room: Room): Record<string, string> {
    const input = layoutInputs.value[room.id] ?? { x: 0, y: 0, layer: 'floor' };
    const item = props.furniture.find((furniture) => furniture.id === selectedFurniture.value[room.id]);
    const top = input.layer === 'floor' ? 43 + (input.y / room.height) * 55 : input.layer === 'wall' ? 5 + (input.y / room.height) * 30 : 2;

    return {
        left: `${(input.x / room.width) * 100}%`,
        top: `${top}%`,
        width: `${(Math.min(item?.w ?? 1, room.width - input.x) / room.width) * 100}%`,
        height: `${(Math.min(item?.h ?? 1, room.height - input.y) / room.height) * (input.layer === 'floor' ? 55 : 30)}%`,
    };
}

function floorGridStyle(room: Room): Record<string, string> {
    const pattern =
        roomStyles[room.id].floor === 'tiles'
            ? 'linear-gradient(rgba(255,255,255,.2) 2px, transparent 2px), linear-gradient(90deg, rgba(255,255,255,.2) 2px, transparent 2px)'
            : roomStyles[room.id].floor === 'grass'
              ? 'radial-gradient(rgba(255,255,255,.2) 1px, transparent 2px)'
              : roomStyles[room.id].floor === 'carpet'
                ? 'repeating-linear-gradient(0deg, transparent 0 24px, rgba(255,255,255,.1) 24px 27px)'
                : 'linear-gradient(rgba(75,45,25,.18) 1px, transparent 1px), linear-gradient(90deg, rgba(75,45,25,.13) 1px, transparent 1px)';

    return {
        backgroundColor: floorColors[roomStyles[room.id].floor] ?? floorColors.planks,
        backgroundImage: pattern,
        backgroundSize: roomStyles[room.id].floor === 'grass' ? '18px 18px' : `${100 / room.width}% ${100 / room.height}%`,
    };
}

function startFurnitureDrag(event: PointerEvent, room: Room, item: Placement) {
    if (!room.can_edit || !item.id) return;
    event.preventDefault();
    (event.currentTarget as HTMLElement).setPointerCapture(event.pointerId);
}

function dragFurniture(event: PointerEvent, room: Room, item: Placement) {
    if (!room.can_edit || !item.id || !(event.currentTarget as HTMLElement).hasPointerCapture(event.pointerId)) return;
    const stage = (event.currentTarget as HTMLElement).parentElement;
    if (!stage) return;
    const bounds = stage.getBoundingClientRect();
    const footprint = placementFootprint(item);
    item.x = Math.max(0, Math.min(room.width - footprint.w, Math.floor(((event.clientX - bounds.left) / bounds.width) * room.width)));
    const yStart = item.layer === 'floor' ? 0.43 : item.layer === 'wall' ? 0.05 : 0;
    const yRange = item.layer === 'floor' ? 0.57 : item.layer === 'wall' ? 0.3 : 1;
    item.y = Math.max(
        0,
        Math.min(
            room.height - footprint.h,
            Math.floor(((event.clientY - bounds.top - bounds.height * yStart) / (bounds.height * yRange)) * room.height),
        ),
    );
}

function endFurnitureDrag(event: PointerEvent) {
    const target = event.currentTarget as HTMLElement;
    if (target.hasPointerCapture(event.pointerId)) target.releasePointerCapture(event.pointerId);
}

const customizeRoom = (room: Room) => router.put(route('housing.rooms.customize', { room: room.id }), roomStyles[room.id], { preserveScroll: true });

const saveLayout = (room: Room) => {
    const items = placementDrafts[room.id].map((item) => ({
        ...(item.id ? { id: item.id } : { item_id: item.item_id }),
        x: item.x,
        y: item.y,
        layer: item.layer,
        rotation: item.rotation,
        mirrored: item.mirrored,
        master: item.master,
    }));
    router.put(
        route('housing.rooms.layout', { room: room.id }),
        { version: room.version, items },
        {
            preserveScroll: true,
            onSuccess: () => router.reload(),
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
        key: item.key,
        name: item.name,
        icon: item.icon,
        w: item.w,
        h: item.h,
        x: input.x,
        y: input.y,
        layer: input.layer,
        rotation: 0,
        mirrored: false,
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

            <section class="grid gap-5 sm:grid-cols-2">
                <Card v-for="room in home.rooms" :key="room.id">
                    <CardHeader class="pb-3">
                        <CardTitle class="flex items-center justify-between gap-3 text-lg">
                            <span>{{ room.name }}</span>
                            <span class="text-xs font-medium text-muted-foreground">{{ room.width }} × {{ room.height }}</span>
                        </CardTitle>
                        <CardDescription>{{ placementDrafts[room.id].length }} Möbelstücke</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div
                            class="housing-room relative isolate aspect-[5/3] w-full cursor-crosshair overflow-hidden rounded-xl border-[6px] border-amber-950/75 shadow-xl ring-1 ring-amber-200/70"
                            :class="room.can_edit && selectedFurniture[room.id] ? 'cursor-crosshair' : 'cursor-default'"
                            :style="{ backgroundColor: wallColors[roomStyles[room.id].wall] ?? wallColors.stone }"
                            :aria-label="`Grafische Ansicht: ${room.name}, ${room.width} mal ${room.height} Felder`"
                            role="group"
                            @click="roomCellClick($event, room)"
                        >
                            <div
                                class="absolute inset-x-0 top-0 h-[44%] border-b-4 border-amber-950/30"
                                :style="{
                                    backgroundImage:
                                        roomStyles[room.id].wall === 'stars'
                                            ? 'radial-gradient(circle at 20% 30%, rgba(255,255,255,.8) 0 1px, transparent 2px), radial-gradient(circle at 70% 55%, rgba(255,255,255,.65) 0 1px, transparent 2px)'
                                            : roomStyles[room.id].wall === 'stripes'
                                              ? 'repeating-linear-gradient(90deg, transparent 0 28px, rgba(255,255,255,.18) 28px 34px)'
                                              : roomStyles[room.id].wall === 'wood'
                                                ? 'repeating-linear-gradient(90deg, rgba(72,42,25,.14) 0 2px, transparent 2px 34px), linear-gradient(180deg, rgba(255,255,255,.23), transparent)'
                                                : roomStyles[room.id].wall === 'stone' || roomStyles[room.id].wall === 'castle'
                                                  ? 'linear-gradient(90deg, transparent 49.5%, rgba(60,50,40,.12) 50%, transparent 50.5%), linear-gradient(180deg, rgba(255,255,255,.25), rgba(70,60,50,.08))'
                                                  : 'linear-gradient(180deg, rgba(255,255,255,.28), rgba(70,40,30,.08))',
                                }"
                            >
                                <div
                                    class="absolute left-[12%] top-[18%] h-[52%] w-[20%] rounded-t-lg border-[5px] border-amber-900/80 bg-sky-200/80 shadow-inner"
                                >
                                    <div class="absolute inset-y-0 left-1/2 border-l-4 border-amber-900/60" />
                                    <div class="absolute inset-x-0 top-1/2 border-t-4 border-amber-900/60" />
                                </div>
                                <div
                                    class="absolute right-[14%] top-[14%] grid h-[46%] w-[17%] place-items-center rounded-sm border-[5px] border-amber-900/75 bg-amber-100/80 shadow-md"
                                >
                                    <svg viewBox="0 0 80 80" class="h-full w-full p-1" aria-hidden="true">
                                        <rect x="3" y="3" width="74" height="74" rx="3" fill="#d9eff4" />
                                        <circle cx="57" cy="23" r="9" fill="#ffd56a" />
                                        <path d="M5 63 27 36l15 18 10-12 23 22v11H5z" fill="#77a979" />
                                        <path d="M5 68q17-9 34 0t36 0v9H5z" fill="#638d72" />
                                    </svg>
                                </div>
                                <span class="absolute inset-x-0 bottom-0 h-2 border-t border-amber-950/25 bg-amber-900/20" />
                            </div>
                            <div class="absolute inset-x-0 bottom-0 top-[43%] border-t-4 border-amber-950/30" :style="floorGridStyle(room)" />
                            <div
                                class="pointer-events-none absolute bottom-[9%] left-[39%] h-[11%] w-[27%] rounded-[50%] border-2 border-rose-950/25 bg-[radial-gradient(ellipse_at_center,_#e9b9a5_0%,_#b56d71_72%,_#78434d_100%)] opacity-80 shadow-inner"
                                aria-hidden="true"
                            >
                                <span class="absolute inset-[17%] rounded-[50%] border border-amber-100/45" />
                            </div>
                            <div
                                class="absolute bottom-0 right-[8%] h-[31%] w-[14%] rounded-t-md border-x-4 border-t-4 border-amber-950/80 bg-gradient-to-r from-amber-900/80 via-amber-700/90 to-amber-900/80 shadow-inner"
                            >
                                <span class="absolute inset-x-1 top-[18%] h-[34%] border-y border-amber-950/40 bg-amber-950/15" />
                                <span class="absolute right-[15%] top-1/2 size-2 rounded-full bg-amber-200 shadow" />
                            </div>
                            <button
                                v-for="(item, index) in placementDrafts[room.id]"
                                :key="item.id ?? `new-${index}`"
                                class="absolute grid touch-none place-items-center overflow-visible rounded-lg border-2 border-transparent bg-transparent p-0 transition-[filter] hover:z-30 hover:brightness-110 focus-visible:z-30 focus-visible:outline-2 focus-visible:outline-violet-600 disabled:cursor-default"
                                :style="placedItemStyle(room, item)"
                                :title="`${item.name} · ${item.x}, ${item.y} · ${item.layer === 'floor' ? 'Boden' : item.layer === 'wall' ? 'Wand' : 'Decke'}`"
                                :aria-label="`${item.name}${room.can_edit ? ', zum Verschieben ziehen' : ''}`"
                                :disabled="!room.can_edit"
                                type="button"
                                @click.stop
                                @pointerdown="startFurnitureDrag($event, room, item)"
                                @pointermove="dragFurniture($event, room, item)"
                                @pointerup="endFurnitureDrag"
                            >
                                <FurnitureArt
                                    :item-key="item.key"
                                    :icon="item.icon"
                                    :name="item.name"
                                    :master="item.master"
                                    :class="{
                                        'rotate-90': item.rotation === 90,
                                        'rotate-180': item.rotation === 180,
                                        '-rotate-90': item.rotation === 270,
                                    }"
                                    class="h-[170%] max-h-none w-[140%] max-w-none drop-shadow-xl"
                                />
                            </button>
                            <div
                                v-if="room.can_edit && selectedFurniture[room.id]"
                                class="pointer-events-none absolute z-20 grid place-items-center rounded-lg border-2 border-dashed border-violet-700 bg-violet-300/40 text-xl shadow-lg"
                                :style="previewStyle(room)"
                                aria-hidden="true"
                            >
                                <FurnitureArt
                                    :item-key="furniture.find((item) => item.id === selectedFurniture[room.id])?.key ?? ''"
                                    :icon="furniture.find((item) => item.id === selectedFurniture[room.id])?.icon ?? '🪑'"
                                    :name="furniture.find((item) => item.id === selectedFurniture[room.id])?.name"
                                    class="h-[170%] max-h-none w-[140%] max-w-none opacity-75 drop-shadow-xl"
                                />
                            </div>
                            <span class="absolute bottom-2 left-2 rounded-md bg-black/55 px-2 py-1 text-[10px] font-bold text-white shadow">
                                {{ home.styles.walls[roomStyles[room.id].wall] }} · {{ home.styles.floors[roomStyles[room.id].floor] }}
                            </span>
                        </div>
                        <p class="mt-2 text-xs text-muted-foreground">
                            <template v-if="room.can_edit"
                                >Möbel auswählen, im Zimmer platzieren und zum Verschieben ziehen. Änderungen anschließend speichern.</template
                            >
                            <template v-else>Deine Möbel und die Gestaltung des Zimmers.</template>
                        </p>
                        <div v-if="placementDrafts[room.id].length" class="flex flex-wrap gap-2">
                            <div
                                v-for="(item, index) in placementDrafts[room.id]"
                                :key="item.id ?? `new-${index}`"
                                class="flex items-center gap-2 rounded-lg bg-muted px-3 py-2 text-sm"
                            >
                                <FurnitureArt :item-key="item.key" :icon="item.icon" :name="item.name" :master="item.master" class="size-8" />
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
                                <div v-if="placementDrafts[room.id].length" class="grid max-h-64 gap-2 overflow-y-auto">
                                    <div
                                        v-for="(item, index) in placementDrafts[room.id]"
                                        :key="item.id ?? `new-${index}`"
                                        class="flex flex-wrap items-center gap-2 rounded-lg bg-muted px-3 py-2 text-sm"
                                    >
                                        <span class="flex items-center gap-2">
                                            <FurnitureArt
                                                :item-key="item.key"
                                                :icon="item.icon"
                                                :name="item.name"
                                                :master="item.master"
                                                :class="{
                                                    'rotate-90': item.rotation === 90,
                                                    'rotate-180': item.rotation === 180,
                                                    '-rotate-90': item.rotation === 270,
                                                }"
                                                class="size-10"
                                            />
                                            {{ item.name }}
                                        </span>
                                        <label class="flex items-center gap-1"
                                            >X
                                            <input
                                                v-model.number="item.x"
                                                type="number"
                                                min="0"
                                                :max="Math.max(0, room.width - placementFootprint(item).w)"
                                                class="h-8 w-16 rounded border bg-background px-2"
                                                @change="clampPlacement(item, room)"
                                        /></label>
                                        <label class="flex items-center gap-1"
                                            >Y
                                            <input
                                                v-model.number="item.y"
                                                type="number"
                                                min="0"
                                                :max="Math.max(0, room.height - placementFootprint(item).h)"
                                                class="h-8 w-16 rounded border bg-background px-2"
                                                @change="clampPlacement(item, room)"
                                        /></label>
                                        <select v-model="item.layer" class="h-8 rounded border bg-background px-2" aria-label="Möbelebene">
                                            <option value="floor">Boden</option>
                                            <option value="wall">Wand</option>
                                            <option value="ceiling">Decke</option>
                                        </select>
                                        <select
                                            v-model.number="item.rotation"
                                            class="h-8 rounded border bg-background px-2"
                                            aria-label="Drehung"
                                            @change="clampPlacement(item, room)"
                                        >
                                            <option :value="0">0°</option>
                                            <option :value="90">90°</option>
                                            <option :value="180">180°</option>
                                            <option :value="270">270°</option>
                                        </select>
                                        <label class="flex items-center gap-1">
                                            <input v-model="item.mirrored" type="checkbox" class="size-4" />
                                            Spiegeln
                                        </label>
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
                                <div v-if="furniture.length" class="grid gap-3">
                                    <div class="grid max-h-80 grid-cols-3 gap-2 overflow-y-auto rounded-xl border bg-muted/30 p-2 sm:grid-cols-4">
                                        <button
                                            v-for="item in furniture"
                                            :key="item.id"
                                            type="button"
                                            :disabled="item.quantity < 1 && item.master_quantity < 1"
                                            :aria-pressed="selectedFurniture[room.id] === item.id"
                                            class="grid min-h-24 content-center justify-items-center gap-1 rounded-lg border bg-background p-2 text-center text-xs transition hover:-translate-y-0.5 hover:border-violet-400 hover:shadow disabled:cursor-not-allowed disabled:opacity-40"
                                            :class="
                                                selectedFurniture[room.id] === item.id
                                                    ? 'border-violet-600 bg-violet-50 ring-2 ring-violet-300 dark:bg-violet-950/40'
                                                    : 'border-border'
                                            "
                                            @click="
                                                selectedFurniture[room.id] = item.id;
                                                prepareLayout(room);
                                            "
                                        >
                                            <FurnitureArt :item-key="item.key" :icon="item.icon" :name="item.name" class="size-12" />
                                            <span class="line-clamp-2 font-semibold">{{ item.name }}</span>
                                            <span class="text-muted-foreground">×{{ item.quantity + item.master_quantity }}</span>
                                        </button>
                                    </div>
                                    <p v-if="!selectedFurniture[room.id]" class="text-xs text-muted-foreground">
                                        Wähle ein Möbelstück und klicke dann an die Stelle im Zimmer, an der es stehen soll.
                                    </p>
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
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            :disabled="!selectedFurniture[room.id]"
                                            @click="addFurniture(room)"
                                        >
                                            Im Zimmer platzieren
                                        </Button>
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
