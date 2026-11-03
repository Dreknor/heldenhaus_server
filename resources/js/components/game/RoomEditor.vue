<script setup lang="ts">
import FurnitureArt from '@/components/game/FurnitureArt.vue';
import { useCelebrate } from '@/composables/useCelebrate';
import { router } from '@inertiajs/vue3';
import { Check, FlipHorizontal2, Pencil, RotateCw, Trash2, Undo2, X } from 'lucide-vue-next';
import { computed, reactive, ref, watch } from 'vue';

/**
 * Ein Zimmer des Familienhauses: große Ansicht, „Einrichten“-Modus mit Möbelkiste, Antippen zum Auswählen,
 * Ziehen zum Verschieben, Pfeiltasten für die Tastatur und ein gemeinsamer Speichern-Knopf für Möbel und Gestaltung.
 * Bewusst ohne Koordinaten-Felder – Kinder (und Menschen mit ADHS) sollen das Zimmer direkt anfassen.
 */
export interface RoomItem {
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
}
export interface Room {
    id: number;
    name: string;
    type: string;
    width: number;
    height: number;
    can_edit: boolean;
    version: number;
    style: { wall: string; floor: string };
    items: RoomItem[];
}
export interface Furniture {
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
type Placement = Omit<RoomItem, 'id' | 'item_id'> & { id?: number; item_id?: number };

const props = defineProps<{ room: Room; furniture: Furniture[]; styles: { walls: Record<string, string>; floors: Record<string, string> } }>();
const { celebrate } = useCelebrate();

const editing = ref(false);
const drafts = ref<Placement[]>([]);
const style = reactive({ name: '', wall: '', floor: '' });
const selectedStock = ref<number | null>(null);
const selectedIndex = ref<number | null>(null);
const pending = reactive({ rotation: 0, mirrored: false, master: false });
const hover = ref<{ x: number; y: number } | null>(null);
const saving = ref(false);
const stage = ref<HTMLElement | null>(null);

function reset() {
    drafts.value = props.room.items.map((i) => ({ ...i }));
    Object.assign(style, { name: props.room.name, wall: props.room.style.wall, floor: props.room.style.floor });
    selectedStock.value = null;
    selectedIndex.value = null;
}
reset();
watch(() => [props.room.version, props.room.items, props.room.style], reset);

const dirty = computed(
    () =>
        style.name !== props.room.name ||
        style.wall !== props.room.style.wall ||
        style.floor !== props.room.style.floor ||
        JSON.stringify(drafts.value.map(strip)) !== JSON.stringify(props.room.items.map(strip)),
);
function strip(p: Placement) {
    return [p.id ?? null, p.item_id, p.x, p.y, p.layer, p.rotation, p.mirrored, p.master];
}

// Möbelkiste: was noch übrig ist, nachdem neue Stücke ins Zimmer gestellt wurden
function remaining(f: Furniture) {
    const placedNew = drafts.value.filter((d) => !d.id && d.item_id === f.id);
    return {
        normal: f.quantity - placedNew.filter((d) => !d.master).length,
        master: f.master_quantity - placedNew.filter((d) => d.master).length,
    };
}
const stock = computed(() => props.furniture.map((f) => ({ ...f, left: remaining(f) })).filter((f) => f.left.normal + f.left.master > 0));
const stockItem = computed(() => props.furniture.find((f) => f.id === selectedStock.value) ?? null);
const selected = computed(() => (selectedIndex.value === null ? null : (drafts.value[selectedIndex.value] ?? null)));
const layersFor = (key: string) => props.furniture.find((f) => f.key === key)?.layers ?? ['floor'];

// Geometrie: Boden unten (43–98 %), Wand oben (5–35 %), Decke ganz oben
const LAYER = { floor: { top: 43, span: 55 }, wall: { top: 5, span: 30 }, ceiling: { top: 2, span: 10 } } as const;
const layerOf = (l: string) => LAYER[l as keyof typeof LAYER] ?? LAYER.floor;
const footprint = (w: number, h: number, rotation: number) => (rotation % 180 === 0 ? { w, h } : { w: h, h: w });

function boxStyle(x: number, y: number, w: number, h: number, layer: string) {
    const l = layerOf(layer);
    return {
        left: `${(x / props.room.width) * 100}%`,
        top: `${l.top + (y / props.room.height) * l.span}%`,
        width: `${(w / props.room.width) * 100}%`,
        height: `${(h / props.room.height) * l.span}%`,
        zIndex: (layer === 'floor' ? 10 + y : layer === 'wall' ? 5 : 40).toString(),
    };
}
// Bodenmöbel stehen auf ihrer Stellfläche und ragen nach oben (wie im echten Zimmer), Wandbilder füllen ihr Feld
function artBox(layer: string): Record<string, string> {
    return layer === 'floor'
        ? { position: 'absolute', left: '0', bottom: '0', width: '100%', height: 'auto', aspectRatio: '1', transformOrigin: '50% 100%' }
        : { position: 'absolute', inset: '-35%', width: '170%', height: '170%' };
}
function artTransform(p: { rotation: number; mirrored: boolean }) {
    return `rotate(${p.rotation}deg) scaleX(${p.mirrored ? -1 : 1})`;
}
function gridPos(clientX: number, clientY: number, layer: string) {
    const b = stage.value!.getBoundingClientRect();
    const l = layerOf(layer);
    return {
        x: ((clientX - b.left) / b.width) * props.room.width,
        y: (((clientY - b.top) / b.height) * 100 - l.top) / (l.span / props.room.height),
    };
}
function clamp(p: Placement) {
    const f = footprint(p.w, p.h, p.rotation);
    p.x = Math.max(0, Math.min(props.room.width - f.w, Math.round(p.x)));
    p.y = Math.max(0, Math.min(props.room.height - f.h, Math.round(p.y)));
}

// Wie auf dem Server: Auf derselben Ebene darf sich nichts überlappen
function overlaps(c: { x: number; y: number; w: number; h: number; layer: string }, ignore: number | null = null) {
    return drafts.value.some((d, i) => {
        if (i === ignore || d.layer !== c.layer) return false;
        const f = footprint(d.w, d.h, d.rotation);
        return c.x < d.x + f.w && d.x < c.x + c.w && c.y < d.y + f.h && d.y < c.y + c.h;
    });
}
function fits(p: Placement, index: number) {
    const f = footprint(p.w, p.h, p.rotation);
    return !overlaps({ x: p.x, y: p.y, w: f.w, h: f.h, layer: p.layer }, index);
}
const blocked = ref(false);

// Vorschau beim Platzieren
const ghost = computed(() => {
    const f = stockItem.value;
    if (!f || !hover.value) return null;
    const layer = f.layers[0] ?? 'floor';
    const fp = footprint(f.w, f.h, pending.rotation);
    const x = Math.max(0, Math.min(props.room.width - fp.w, Math.floor(hover.value.x - fp.w / 2 + 0.5)));
    const y = Math.max(0, Math.min(props.room.height - fp.h, Math.floor(hover.value.y - fp.h / 2 + 0.5)));
    return { f, layer, x, y, fp, free: !overlaps({ x, y, w: fp.w, h: fp.h, layer }) };
});
function onStageMove(e: PointerEvent) {
    if (!editing.value || !stockItem.value) return;
    hover.value = gridPos(e.clientX, e.clientY, stockItem.value.layers[0] ?? 'floor');
}
function onStageClick(e: MouseEvent) {
    if (!editing.value) return;
    if (!stockItem.value) {
        selectedIndex.value = null;
        return;
    }
    hover.value = gridPos(e.clientX, e.clientY, stockItem.value.layers[0] ?? 'floor');
    const g = ghost.value;
    if (!g) return;
    if (!g.free) {
        blocked.value = true;
        setTimeout(() => (blocked.value = false), 1600);
        return;
    }
    const left = remaining(g.f);
    drafts.value.push({
        item_id: g.f.id,
        key: g.f.key,
        name: g.f.name,
        icon: g.f.icon,
        w: g.f.w,
        h: g.f.h,
        x: g.x,
        y: g.y,
        layer: g.layer,
        rotation: pending.rotation,
        mirrored: pending.mirrored,
        master: pending.master && left.master > 0 ? true : left.normal < 1,
    });
    selectedIndex.value = drafts.value.length - 1;
    celebrate('step', null);
    if (remaining(g.f).normal + remaining(g.f).master < 1) selectedStock.value = null;
}
function pickStock(id: number) {
    selectedStock.value = selectedStock.value === id ? null : id;
    selectedIndex.value = null;
    Object.assign(pending, { rotation: 0, mirrored: false, master: false });
}

// Verschieben per Ziehen
let drag: { index: number; pointerId: number; dx: number; dy: number; moved: boolean } | null = null;
function startDrag(e: PointerEvent, index: number) {
    if (!editing.value) return;
    e.stopPropagation();
    selectedStock.value = null;
    selectedIndex.value = index;
    const p = drafts.value[index];
    const pos = gridPos(e.clientX, e.clientY, p.layer);
    drag = { index, pointerId: e.pointerId, dx: pos.x - p.x, dy: pos.y - p.y, moved: false };
    (e.currentTarget as HTMLElement).setPointerCapture(e.pointerId);
}
function moveDrag(e: PointerEvent) {
    if (!drag || drag.pointerId !== e.pointerId) return;
    const p = drafts.value[drag.index];
    const pos = gridPos(e.clientX, e.clientY, p.layer);
    const before = { x: p.x, y: p.y };
    p.x = Math.floor(pos.x - drag.dx + 0.5);
    p.y = Math.floor(pos.y - drag.dy + 0.5);
    clamp(p);
    if (!fits(p, drag.index)) Object.assign(p, before);
    drag.moved = true;
}
function endDrag(e: PointerEvent) {
    const t = e.currentTarget as HTMLElement;
    if (t.hasPointerCapture(e.pointerId)) t.releasePointerCapture(e.pointerId);
    drag = null;
}
function onItemKey(e: KeyboardEvent, index: number) {
    if (!editing.value) return;
    const p = drafts.value[index];
    const moves: Record<string, [number, number]> = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1] };
    if (moves[e.key]) {
        e.preventDefault();
        const before = { x: p.x, y: p.y };
        p.x += moves[e.key][0];
        p.y += moves[e.key][1];
        clamp(p);
        if (!fits(p, index)) Object.assign(p, before);
    } else if (e.key === 'Delete' || e.key === 'Backspace') {
        e.preventDefault();
        removeSelected();
    } else if (e.key.toLowerCase() === 'r') {
        rotateSelected();
    }
}

function rotateSelected() {
    const p = selected.value;
    if (!p) return;
    const before = { rotation: p.rotation, x: p.x, y: p.y };
    p.rotation = (p.rotation + 90) % 360;
    clamp(p);
    if (!fits(p, selectedIndex.value!)) Object.assign(p, before);
}
function cycleLayer() {
    const p = selected.value;
    if (!p) return;
    const layers = layersFor(p.key);
    const before = { layer: p.layer, x: p.x, y: p.y };
    p.layer = layers[(layers.indexOf(p.layer) + 1) % layers.length];
    clamp(p);
    if (!fits(p, selectedIndex.value!)) Object.assign(p, before);
}
function removeSelected() {
    if (selectedIndex.value === null) return;
    drafts.value.splice(selectedIndex.value, 1);
    selectedIndex.value = null;
}

function startEditing() {
    reset();
    editing.value = true;
}
function cancel() {
    reset();
    editing.value = false;
}
function save() {
    saving.value = true;
    const styleChanged = style.name !== props.room.name || style.wall !== props.room.style.wall || style.floor !== props.room.style.floor;
    const items = drafts.value.map((d) => ({
        ...(d.id ? { id: d.id } : { item_id: d.item_id }),
        x: d.x,
        y: d.y,
        layer: d.layer,
        rotation: d.rotation,
        mirrored: d.mirrored,
        master: d.master,
    }));
    const finish = () => {
        saving.value = false;
        editing.value = false;
        celebrate('task', null, 'Gemütlich!');
    };
    router.put(
        route('housing.rooms.layout', { room: props.room.id }),
        { version: props.room.version, items },
        {
            preserveScroll: true,
            onSuccess: () =>
                styleChanged
                    ? router.put(route('housing.rooms.customize', { room: props.room.id }), { ...style }, { preserveScroll: true, onSuccess: finish })
                    : finish(),
            onError: () => (saving.value = false),
        },
    );
}

// Optik der Wände und Böden
const WALL: Record<string, { base: string; image: string }> = {
    stone: {
        base: '#c9c4d6',
        image: 'linear-gradient(90deg, transparent 49%, rgba(60,50,80,.10) 50%, transparent 51%), linear-gradient(0deg, transparent 49%, rgba(60,50,80,.10) 50%, transparent 51%)',
    },
    wood: { base: '#c08a62', image: 'repeating-linear-gradient(90deg, rgba(72,42,25,.16) 0 2px, transparent 2px 38px)' },
    stars: {
        base: '#4c4787',
        image: 'radial-gradient(circle at 20% 30%, #fff 0 1.2px, transparent 2px), radial-gradient(circle at 70% 60%, #fde68a 0 1.4px, transparent 2.2px), radial-gradient(circle at 45% 80%, #fff 0 1px, transparent 2px)',
    },
    stripes: { base: '#f2a7b0', image: 'repeating-linear-gradient(90deg, transparent 0 26px, rgba(255,255,255,.35) 26px 34px)' },
    pastel: {
        base: '#f6d4e1',
        image: 'radial-gradient(circle at 25% 25%, rgba(255,255,255,.6) 0 3px, transparent 4px), radial-gradient(circle at 75% 75%, rgba(167,139,250,.35) 0 3px, transparent 4px)',
    },
    castle: {
        base: '#9aa6bf',
        image: 'linear-gradient(90deg, transparent 48%, rgba(40,50,70,.18) 49%, transparent 52%), linear-gradient(0deg, transparent 46%, rgba(40,50,70,.18) 48%, transparent 54%)',
    },
};
const FLOOR: Record<string, { base: string; image: string; size: string }> = {
    planks: {
        base: '#c99766',
        image: 'repeating-linear-gradient(0deg, rgba(90,50,25,.22) 0 2px, transparent 2px 22px), repeating-linear-gradient(90deg, transparent 0 90px, rgba(90,50,25,.14) 90px 92px)',
        size: 'auto',
    },
    stone: {
        base: '#a9b4c0',
        image: 'linear-gradient(90deg, rgba(50,60,70,.18) 2px, transparent 2px), linear-gradient(0deg, rgba(50,60,70,.18) 2px, transparent 2px)',
        size: '48px 32px',
    },
    carpet: { base: '#c97b8b', image: 'repeating-linear-gradient(45deg, rgba(255,255,255,.08) 0 10px, transparent 10px 20px)', size: 'auto' },
    grass: { base: '#86b86a', image: 'radial-gradient(rgba(255,255,255,.25) 1px, transparent 2px)', size: '14px 14px' },
    tiles: {
        base: '#8fcfcd',
        image: 'linear-gradient(rgba(255,255,255,.35) 2px, transparent 2px), linear-gradient(90deg, rgba(255,255,255,.35) 2px, transparent 2px)',
        size: '36px 36px',
    },
};
const wall = computed(() => WALL[style.wall] ?? WALL.stone);
const floor = computed(() => FLOOR[style.floor] ?? FLOOR.planks);
</script>

<template>
    <div class="grid gap-4" :class="editing ? 'lg:grid-cols-[1fr_18rem]' : ''">
        <div class="grid content-start gap-3">
            <!-- Bühne -->
            <div
                ref="stage"
                class="room relative isolate aspect-[5/3] w-full touch-none select-none overflow-hidden rounded-3xl border-[6px] border-amber-900/80 shadow-[0_18px_30px_-18px_rgba(69,38,24,.7)]"
                :class="editing && stockItem ? 'cursor-copy' : 'cursor-default'"
                role="group"
                :aria-label="`Zimmer ${style.name}`"
                @pointermove="onStageMove"
                @pointerleave="hover = null"
                @click="onStageClick"
            >
                <!-- Wand -->
                <div
                    class="absolute inset-x-0 top-0 h-[44%]"
                    :style="{
                        backgroundColor: wall.base,
                        backgroundImage: wall.image,
                        backgroundSize: style.wall === 'stone' || style.wall === 'castle' ? '64px 32px' : 'auto',
                    }"
                >
                    <div class="absolute inset-0 bg-gradient-to-b from-white/30 via-transparent to-black/10" />
                    <div class="absolute inset-x-0 bottom-0 h-[26%] border-t-4 border-black/10 bg-black/10" />
                    <div
                        class="absolute left-[10%] top-[14%] h-[54%] w-[15%] rounded-t-full border-[5px] border-amber-50 bg-gradient-to-b from-sky-300 to-sky-100 shadow-inner"
                        aria-hidden="true"
                    >
                        <span class="absolute inset-y-0 left-1/2 w-[5px] -translate-x-1/2 bg-amber-50" />
                        <span class="absolute inset-x-0 top-1/2 h-[5px] bg-amber-50" />
                    </div>
                </div>
                <div class="absolute inset-x-0 top-[43%] h-[2.5%] bg-amber-950/45" />
                <!-- Boden -->
                <div
                    class="absolute inset-x-0 bottom-0 top-[45%]"
                    :style="{ backgroundColor: floor.base, backgroundImage: floor.image, backgroundSize: floor.size }"
                >
                    <div class="absolute inset-0 bg-gradient-to-b from-black/25 via-transparent to-white/10" />
                </div>
                <!-- Licht vom Fenster und Rand-Abdunklung -->
                <div
                    class="pointer-events-none absolute inset-0 z-[60] bg-[radial-gradient(ellipse_45%_60%_at_18%_30%,rgba(255,236,170,.35),transparent_70%)]"
                />
                <div class="pointer-events-none absolute inset-0 z-[60] shadow-[inset_0_0_60px_rgba(40,20,10,.35)]" />
                <!-- Raster im Bearbeiten-Modus -->
                <div
                    v-if="editing"
                    class="pointer-events-none absolute inset-x-0 bottom-[2%] top-[43%] z-[1] opacity-40"
                    :style="{
                        backgroundImage: 'radial-gradient(circle, rgba(255,255,255,.9) 1.5px, transparent 2px)',
                        backgroundSize: `${100 / room.width}% ${100 / room.height}%`,
                    }"
                />

                <!-- Möbel -->
                <button
                    v-for="(p, i) in drafts"
                    :key="p.id ?? `n${i}`"
                    type="button"
                    class="absolute grid place-items-center rounded-xl outline-none transition-[filter,box-shadow]"
                    :class="[
                        editing ? 'cursor-grab active:cursor-grabbing' : 'cursor-default',
                        selectedIndex === i && editing ? 'ring-4 ring-violet-500/80 ring-offset-2 ring-offset-transparent' : '',
                        editing ? 'focus-visible:ring-4 focus-visible:ring-violet-500' : '',
                    ]"
                    :style="boxStyle(p.x, p.y, footprint(p.w, p.h, p.rotation).w, footprint(p.w, p.h, p.rotation).h, p.layer)"
                    :aria-label="`${p.name}${editing ? ' – ziehen zum Verschieben, Pfeiltasten bewegen, R dreht, Entf entfernt' : ''}`"
                    :tabindex="editing ? 0 : -1"
                    @pointerdown="startDrag($event, i)"
                    @pointermove="moveDrag"
                    @pointerup="endDrag"
                    @pointercancel="endDrag"
                    @click.stop="editing && (selectedIndex = i)"
                    @keydown="onItemKey($event, i)"
                >
                    <FurnitureArt
                        :item-key="p.key"
                        :icon="p.icon"
                        :name="p.name"
                        :master="p.master"
                        class="pointer-events-none drop-shadow-lg"
                        :style="{ ...artBox(p.layer), transform: artTransform(p) }"
                    />
                </button>

                <!-- Vorschau -->
                <div
                    v-if="ghost"
                    class="pointer-events-none absolute grid place-items-center rounded-xl border-[3px] border-dashed"
                    :class="ghost.free ? 'border-violet-600 bg-violet-300/30' : 'border-rose-600 bg-rose-400/40'"
                    :style="{ ...boxStyle(ghost.x, ghost.y, ghost.fp.w, ghost.fp.h, ghost.layer), zIndex: '50' }"
                >
                    <FurnitureArt
                        :item-key="ghost.f.key"
                        :icon="ghost.f.icon"
                        :name="ghost.f.name"
                        class="pointer-events-none opacity-75"
                        :style="{ ...artBox(ghost.layer), transform: artTransform(pending) }"
                    />
                </div>

                <p v-if="!drafts.length && !editing" class="absolute inset-x-0 top-[62%] z-[61] text-center text-sm font-bold text-white drop-shadow">
                    Noch leer – {{ room.can_edit ? 'tippe auf „Einrichten“' : 'hier gibt es bald Möbel' }}
                </p>
            </div>

            <!-- Werkzeugleiste -->
            <div class="flex flex-wrap items-center gap-2">
                <template v-if="!editing">
                    <p class="text-sm font-semibold text-muted-foreground">{{ drafts.length }} Möbelstücke</p>
                    <button
                        v-if="room.can_edit"
                        class="ml-auto flex items-center gap-2 rounded-xl bg-violet-600 px-4 py-2.5 font-bold text-white shadow hover:bg-violet-700"
                        @click="startEditing"
                    >
                        <Pencil class="size-4" /> Einrichten
                    </button>
                </template>
                <template v-else>
                    <template v-if="selected">
                        <span class="mr-1 text-sm font-bold">{{ selected.name }}:</span>
                        <button class="tool" @click="rotateSelected"><RotateCw class="size-4" /> Drehen</button>
                        <button class="tool" :aria-pressed="selected.mirrored" @click="selected.mirrored = !selected.mirrored">
                            <FlipHorizontal2 class="size-4" /> Spiegeln
                        </button>
                        <button v-if="layersFor(selected.key).length > 1" class="tool" @click="cycleLayer">
                            {{ selected.layer === 'floor' ? 'Auf den Boden' : selected.layer === 'wall' ? 'An die Wand' : 'An die Decke' }} ↻
                        </button>
                        <button class="tool text-rose-600" @click="removeSelected"><Trash2 class="size-4" /> Wegräumen</button>
                    </template>
                    <p v-else-if="stockItem" class="text-sm font-semibold text-violet-700 dark:text-violet-300">
                        {{ blocked ? 'Da steht schon etwas – such dir einen freien Platz.' : `Tippe ins Zimmer, wo ${stockItem.name} stehen soll.` }}
                        <button class="tool ml-1" @click.stop="pending.rotation = (pending.rotation + 90) % 360">
                            <RotateCw class="size-4" /> Drehen
                        </button>
                    </p>
                    <p v-else class="text-sm text-muted-foreground">Wähle in der Möbelkiste ein Möbelstück – oder tippe eins im Zimmer an, um es zu ändern.</p>
                    <div class="ml-auto flex gap-2">
                        <button class="tool" @click="cancel"><X class="size-4" /> Abbrechen</button>
                        <button
                            class="flex items-center gap-1.5 rounded-xl bg-gradient-to-b from-emerald-400 to-emerald-600 px-4 py-2 font-black text-white shadow-[0_3px_0_#047857] disabled:opacity-50"
                            :disabled="!dirty || saving"
                            @click="save"
                        >
                            <Check class="size-4 stroke-[3]" /> Speichern
                        </button>
                    </div>
                </template>
            </div>
        </div>

        <!-- Möbelkiste und Gestaltung -->
        <aside v-if="editing" class="grid content-start gap-4 rounded-3xl border-2 bg-card p-4">
            <div>
                <h3 class="mb-2 font-extrabold">Möbelkiste</h3>
                <div v-if="stock.length" class="grid max-h-72 grid-cols-3 gap-2 overflow-y-auto pr-1">
                    <button
                        v-for="f in stock"
                        :key="f.id"
                        type="button"
                        class="relative grid justify-items-center gap-1 rounded-2xl border-2 bg-background p-2 text-center transition hover:-translate-y-0.5 hover:border-violet-400"
                        :class="selectedStock === f.id ? 'border-violet-600 bg-violet-50 ring-2 ring-violet-300 dark:bg-violet-950/40' : ''"
                        :aria-pressed="selectedStock === f.id"
                        :title="f.name"
                        @click="pickStock(f.id)"
                    >
                        <FurnitureArt :item-key="f.key" :icon="f.icon" :name="f.name" class="size-12" />
                        <span class="line-clamp-1 text-[11px] font-semibold">{{ f.name }}</span>
                        <span class="absolute right-1 top-1 rounded-full bg-amber-500 px-1.5 text-[10px] font-black text-white">{{
                            f.left.normal + f.left.master
                        }}</span>
                    </button>
                </div>
                <p v-else class="rounded-2xl border-2 border-dashed p-4 text-center text-sm text-muted-foreground">
                    Keine Möbel übrig. Neue baust du in der Werkstatt.
                </p>
                <label v-if="stockItem && remaining(stockItem).master > 0" class="mt-2 flex items-center gap-2 text-sm font-semibold">
                    <input v-model="pending.master" type="checkbox" class="size-4 accent-amber-500" /> Meisterstück verwenden
                </label>
            </div>

            <div class="grid gap-2 border-t pt-4">
                <h3 class="font-extrabold">Gestaltung</h3>
                <input
                    v-model="style.name"
                    maxlength="40"
                    class="h-10 rounded-xl border-2 bg-background px-3 font-semibold"
                    aria-label="Zimmername"
                />
                <p class="text-xs font-bold text-muted-foreground">Wand</p>
                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="(label, key) in styles.walls"
                        :key="key"
                        type="button"
                        class="size-10 rounded-xl border-2 shadow-sm transition"
                        :class="style.wall === key ? 'scale-110 border-violet-600 ring-2 ring-violet-300' : 'border-white'"
                        :style="{ backgroundColor: WALL[key]?.base, backgroundImage: WALL[key]?.image, backgroundSize: '24px 12px' }"
                        :title="label"
                        :aria-label="`Wand: ${label}`"
                        :aria-pressed="style.wall === key"
                        @click="style.wall = String(key)"
                    />
                </div>
                <p class="text-xs font-bold text-muted-foreground">Boden</p>
                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="(label, key) in styles.floors"
                        :key="key"
                        type="button"
                        class="size-10 rounded-xl border-2 shadow-sm transition"
                        :class="style.floor === key ? 'scale-110 border-violet-600 ring-2 ring-violet-300' : 'border-white'"
                        :style="{ backgroundColor: FLOOR[key]?.base, backgroundImage: FLOOR[key]?.image, backgroundSize: '16px 16px' }"
                        :title="label"
                        :aria-label="`Boden: ${label}`"
                        :aria-pressed="style.floor === key"
                        @click="style.floor = String(key)"
                    />
                </div>
            </div>
            <p class="flex items-center gap-1 text-xs text-muted-foreground">
                <Undo2 class="size-3" /> Weggeräumte Möbel gehen zurück an die Person, die sie aufgestellt hat.
            </p>
        </aside>
    </div>
</template>

<style scoped>
.tool {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    border-radius: 0.75rem;
    border-width: 2px;
    padding: 0.4rem 0.75rem;
    font-size: 0.875rem;
    font-weight: 700;
    background: var(--card, #fff);
}
.tool:hover {
    background: var(--muted, #f4f4f5);
}
</style>
