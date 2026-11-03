<script setup lang="ts">
import HeroAvatar from '@/components/game/HeroAvatar.vue';
import MonsterArt, { type MonsterArtConfig } from '@/components/game/MonsterArt.vue';
import type { AvatarConfig, Equipment } from '@/lib/avatar';
import {
    type DungeonArt,
    type IsoSprite,
    type MapRoom,
    type Side,
    IMAGE_H,
    ROOM_CENTER,
    STRIDE,
    TILE_H,
    TILE_W,
    cellTop,
    compose,
    corridorCell,
    fogSprites,
    knownSprites,
    loadBlock,
    roomBounds,
    roomCellCenter,
    spriteImage,
} from '@/lib/dungeonMap';
import { useResizeObserver } from '@vueuse/core';
import { computed, onMounted, ref, shallowRef, watch } from 'vue';

/**
 * Dungeon als isometrische Karte aus den Kenney-Bausteinen (wie in der App): alle entdeckten Räume mit Gängen,
 * Nebelräume dunkel, Fackelschein um den eigenen Raum. Türen, Figuren und Monster liegen als Bedienelemente darüber.
 */
type Door = { to: number; kind: string; sprite: string };
type Room = MapRoom & {
    type?: string;
    doors?: Partial<Record<Side, Door | null>>;
    monster?: { name: string; hp: number; max_hp: number; art: MonsterArtConfig | null };
    puzzle?: { solved: boolean };
    campfire?: unknown;
};
type Member = {
    user_id: number;
    name: string;
    room: number;
    hp: number;
    class: string | null;
    appearance?: { avatar?: Partial<AvatarConfig>; seed?: number; equipment?: Equipment; class_key?: string | null } | null;
};

const props = defineProps<{
    rooms: Room[];
    edges: { a: number; b: number; kind: string }[];
    members: Member[];
    art: DungeonArt;
    myRoom: number;
    myId: number | null;
    roomIcons: Record<string, string>;
    roomNames: Record<string, string>;
    /** Türen dürfen bedient werden (aktiv, nicht beschäftigt) */
    interactive: boolean;
}>();
const emit = defineEmits<{ door: [door: Door, side: Side]; monster: [] }>();

const box = ref<HTMLDivElement | null>(null);
const canvas = ref<HTMLCanvasElement | null>(null);
const width = ref(0);
const whole = ref(props.rooms.length <= 4);
const plans = shallowRef(new Map<number, { sprites: IsoSprite[]; spots: Record<string, { col: number; row: number }> }>());

const roomById = computed(() => new Map(props.rooms.map((room) => [room.id, room])));
const current = computed(() => roomById.value.get(props.myRoom));
const height = computed(() => Math.round(Math.min(560, Math.max(300, width.value * 0.62))));

/** Sichtbereich in Weltkoordinaten: eigener Raum + Nachbarn oder die ganze Karte */
const view = computed(() => {
    const me = current.value;
    const shown = props.rooms.filter((room) => whole.value || !me || Math.abs(room.x - me.x) + Math.abs(room.y - me.y) <= 1);
    const list = shown.length ? shown : props.rooms;
    const b = list.map(roomBounds);
    const rect = {
        left: Math.min(...b.map((r) => r.left)) - TILE_W / 2,
        right: Math.max(...b.map((r) => r.right)) + TILE_W / 2,
        top: Math.min(...b.map((r) => r.top)),
        bottom: Math.max(...b.map((r) => r.bottom)) + TILE_H / 2,
    };
    const scale = Math.min(width.value / (rect.right - rect.left), height.value / (rect.bottom - rect.top)) || 0.1;
    // Zentriert im Kasten
    const ox = (width.value / scale - (rect.right - rect.left)) / 2 - rect.left;
    const oy = (height.value / scale - (rect.bottom - rect.top)) / 2 - rect.top;
    return { scale, ox, oy };
});

function toScreen(p: { x: number; y: number }) {
    return { x: (p.x + view.value.ox) * view.value.scale, y: (p.y + view.value.oy) * view.value.scale };
}

let images = new Map<string, HTMLImageElement>();

async function plan() {
    const known = await knownSprites(props.art);
    const next = new Map<number, { sprites: IsoSprite[]; spots: Record<string, { col: number; row: number }> }>();
    await Promise.all(
        props.rooms.map(async (room) => {
            if (room.fog || !room.block) {
                next.set(room.id, { sprites: fogSprites(), spots: {} });
                return;
            }
            const block = await loadBlock(props.art, room.block);
            next.set(room.id, { sprites: compose(block, room, props.art, known), spots: block.spots });
        }),
    );
    const keys = new Set([...next.values()].flatMap((p) => p.sprites.map((s) => s.key)).concat('dirtTiles_S', 'stone_S'));
    const loaded = await Promise.all([...keys].map(async (key) => [key, await spriteImage(props.art, key)] as const));
    images = new Map(loaded.filter((entry): entry is [string, HTMLImageElement] => entry[1] !== null));
    plans.value = next;
}

// Abgedunkelte Kopien der Sprites (canvas.filter fehlt in Safari)
const shaded = new Map<string, HTMLCanvasElement>();
function shade(img: HTMLImageElement, key: string, dark: number): CanvasImageSource {
    if (dark <= 0) return img;
    const id = `${key}:${dark}`;
    let c = shaded.get(id);
    if (!c) {
        c = document.createElement('canvas');
        c.width = img.naturalWidth;
        c.height = img.naturalHeight;
        const g = c.getContext('2d')!;
        g.drawImage(img, 0, 0);
        g.globalCompositeOperation = 'source-atop';
        g.fillStyle = `rgba(12, 8, 20, ${dark})`;
        g.fillRect(0, 0, c.width, c.height);
        shaded.set(id, c);
    }
    return c;
}

function draw() {
    const el = canvas.value;
    if (!el || !width.value || !plans.value.size) return;
    const ratio = window.devicePixelRatio || 1;
    el.width = Math.round(width.value * ratio);
    el.height = Math.round(height.value * ratio);
    const g = el.getContext('2d')!;
    g.setTransform(1, 0, 0, 1, 0, 0);
    g.fillStyle = '#120d0a';
    g.fillRect(0, 0, el.width, el.height);
    const { scale, ox, oy } = view.value;
    g.setTransform(scale * ratio, 0, 0, scale * ratio, ox * scale * ratio, oy * scale * ratio);
    g.imageSmoothingEnabled = true;

    const put = (key: string, col: number, row: number, dark: number) => {
        const img = images.get(key);
        if (!img) return;
        const top = cellTop(col, row);
        g.drawImage(shade(img, key, dark), top.x - TILE_W / 2, top.y + TILE_H - IMAGE_H, TILE_W, IMAGE_H);
    };

    // Gänge zuerst, damit die Wände der Räume davor liegen
    for (const edge of props.edges) {
        const a = roomById.value.get(edge.a);
        const b = roomById.value.get(edge.b);
        const cell = a && b ? corridorCell(a, b) : null;
        if (cell) put('dirtTiles_S', cell.col, cell.row, a!.fog || b!.fog ? 0.6 : 0.15);
    }
    // Räume von hinten nach vorn
    const order = [...props.rooms].sort((p, q) => p.x + p.y - (q.x + q.y) || p.x - q.x);
    for (const room of order) {
        const p = plans.value.get(room.id);
        if (!p) continue;
        const dark = room.fog ? 0.78 : room.id === props.myRoom ? 0 : 0.28;
        for (const s of p.sprites) put(s.key, room.x * STRIDE + s.col, room.y * STRIDE + s.row, dark);
    }

    // Fackelschein um den eigenen Raum
    const me = current.value;
    if (me) {
        const c = toScreen(roomCellCenter(me, ROOM_CENTER, ROOM_CENTER));
        g.setTransform(ratio, 0, 0, ratio, 0, 0);
        const r = Math.max(width.value, height.value) * 0.75;
        const light = g.createRadialGradient(c.x, c.y, r * 0.18, c.x, c.y, r);
        light.addColorStop(0, 'rgba(255, 170, 70, 0.10)');
        light.addColorStop(0.35, 'rgba(0, 0, 0, 0)');
        light.addColorStop(1, 'rgba(8, 5, 3, 0.75)');
        g.fillStyle = light;
        g.fillRect(0, 0, width.value, height.value);
    }
}

onMounted(plan);
watch(() => [props.rooms, props.edges], plan, { deep: true });
watch([plans, view, height], () => draw());
useResizeObserver(box, (entries) => (width.value = entries[0].contentRect.width));

function spotOf(room: Room, ...names: string[]) {
    const spots = plans.value.get(room.id)?.spots ?? {};
    for (const name of names) if (spots[name]) return spots[name];
    return { col: ROOM_CENTER, row: ROOM_CENTER };
}

const unit = computed(() => TILE_W * view.value.scale);

/** Figuren je Raum, nebeneinander um den Startpunkt */
const figures = computed(() =>
    props.rooms.flatMap((room) => {
        if (room.fog) return [];
        const here = props.members.filter((m) => m.room === room.id);
        const spot = spotOf(room, 'spawn');
        return here.map((member, i) => {
            const shift = (i - (here.length - 1) / 2) * 0.55;
            const p = toScreen(roomCellCenter(room, spot.col + shift, spot.row - shift));
            return { member, x: p.x, y: p.y, me: member.user_id === props.myId };
        });
    }),
);

const monsters = computed(() =>
    props.rooms
        .filter((room) => room.monster)
        .map((room) => {
            const spot = spotOf(room, room.type === 'boss' ? 'boss' : 'monster', 'monster');
            const p = toScreen(roomCellCenter(room, spot.col, spot.row));
            const alive = room.monster!.hp > 0;
            return { room, x: p.x, y: p.y, alive, here: room.id === props.myRoom };
        }),
);

const doorKinds: Record<string, { icon: string; label: string; tone: string }> = {
    open: { icon: '➜', label: 'Durchgang', tone: 'bg-[var(--gold)] text-[var(--wood-edge)]' },
    locked: { icon: '🔒', label: 'Verschlossene Tür', tone: 'bg-amber-200 text-amber-950' },
    gate: { icon: '⛓️', label: 'Tor (Hebel)', tone: 'bg-slate-300 text-slate-900' },
    cracked: { icon: '🪨', label: 'Rissige Wand', tone: 'bg-stone-300 text-stone-900' },
};
const outward: Record<Side, [number, number]> = { N: [0, -1], S: [0, 1], W: [-1, 0], E: [1, 0] };
const sideNames: Record<Side, string> = { N: 'Norden', E: 'Osten', S: 'Süden', W: 'Westen' };

/** Türen: im eigenen Raum bedienbar, sonst nur Schlösser und Tore als Hinweis */
const doors = computed(() =>
    props.rooms.flatMap((room) => {
        if (room.fog || !room.doors) return [];
        const mine = room.id === props.myRoom;
        return (Object.entries(room.doors) as [Side, Door | null][])
            .filter(([, door]) => door && (mine || door.kind !== 'open'))
            .map(([side, door]) => {
                const spot = spotOf(room, `door_${side}`);
                // Etwas nach außen in den Gang, damit Figuren am Startpunkt frei bleiben
                const [dc, dr] = outward[side];
                const p = toScreen(roomCellCenter(room, spot.col + dc, spot.row + dr));
                return { room, side, door: door!, mine, x: p.x, y: p.y };
            });
    }),
);

const markers = computed(() =>
    props.rooms.map((room) => {
        const p = toScreen(roomCellCenter(room, ROOM_CENTER, ROOM_CENTER));
        return { room, x: p.x, y: p.y };
    }),
);
</script>

<template>
    <div
        class="dungeon-map relative w-full overflow-hidden rounded-md border-2 border-[var(--wood-edge)] bg-[#120d0a]"
        :style="{ height: `${height}px` }"
    >
        <div ref="box" class="absolute inset-0">
            <canvas ref="canvas" class="absolute inset-0 h-full w-full" aria-hidden="true" />

            <!-- Raumzeichen: Nebel und Art des Raums -->
            <template v-for="m in markers" :key="`mark-${m.room.id}`">
                <span
                    v-if="m.room.fog"
                    class="fog pointer-events-none absolute -translate-x-1/2 -translate-y-1/2 select-none"
                    :style="{ left: `${m.x}px`, top: `${m.y}px`, fontSize: `${Math.max(16, unit * 1.1)}px` }"
                    >❔</span
                >
                <span
                    v-else-if="m.room.id !== myRoom && !m.room.monster"
                    class="pointer-events-none absolute -translate-x-1/2 -translate-y-1/2 select-none rounded-full border-2 border-[var(--wood-edge)] bg-[#2b1d12]/85 px-1.5 leading-none shadow"
                    :style="{
                        left: `${m.x}px`,
                        top: `${m.y}px`,
                        fontSize: `${Math.max(12, unit * 0.45)}px`,
                        paddingBlock: `${Math.max(2, unit * 0.08)}px`,
                    }"
                    :title="roomNames[m.room.type ?? ''] ?? 'Raum'"
                    >{{ roomIcons[m.room.type ?? ''] ?? '🪨' }}</span
                >
            </template>

            <!-- Monster -->
            <button
                v-for="mon in monsters"
                :key="`mon-${mon.room.id}`"
                type="button"
                class="absolute -translate-x-1/2 -translate-y-[85%] transition"
                :class="[
                    mon.alive ? '' : 'opacity-50 grayscale',
                    mon.here && mon.alive && interactive ? 'cursor-pointer hover:scale-105' : 'pointer-events-none',
                ]"
                :style="{ left: `${mon.x}px`, top: `${mon.y}px` }"
                :title="mon.alive ? `${mon.room.monster!.name} angreifen` : `${mon.room.monster!.name} (besiegt)`"
                :disabled="!(mon.here && mon.alive && interactive)"
                @click="emit('monster')"
            >
                <MonsterArt
                    :art="mon.room.monster!.art"
                    :size="Math.max(36, unit * (mon.room.type === 'boss' ? 1.7 : 1.15))"
                    :defeated="!mon.alive"
                />
                <span v-if="mon.alive" class="mx-auto mt-0.5 block h-1.5 w-3/4 overflow-hidden rounded-full border border-black/60 bg-black/50">
                    <span class="block h-full bg-rose-500" :style="{ width: `${(mon.room.monster!.hp / mon.room.monster!.max_hp) * 100}%` }" />
                </span>
            </button>

            <!-- Figuren -->
            <div
                v-for="f in figures"
                :key="`hero-${f.member.user_id}`"
                class="pointer-events-none absolute z-10 flex -translate-x-1/2 -translate-y-[92%] flex-col items-center"
                :style="{ left: `${f.x}px`, top: `${f.y}px` }"
            >
                <span
                    class="mb-0.5 whitespace-nowrap rounded-sm px-1 font-pixel leading-tight shadow"
                    :class="f.me ? 'bg-[var(--gold)] text-[var(--wood-edge)]' : 'bg-black/60 text-amber-50'"
                    :style="{ fontSize: `${Math.max(10, unit * 0.16)}px` }"
                    >{{ f.me ? 'Du' : f.member.name }}</span
                >
                <HeroAvatar
                    :avatar="f.member.appearance?.avatar"
                    :seed="f.member.appearance?.seed ?? f.member.user_id"
                    :equipment="f.member.appearance?.equipment"
                    :class-key="f.member.appearance?.class_key ?? f.member.class"
                    :size="Math.max(34, unit * 0.95)"
                    :backdrop="false"
                    class="drop-shadow-[0_3px_2px_rgba(0,0,0,0.6)]"
                />
            </div>

            <!-- Türen -->
            <button
                v-for="d in doors"
                :key="`door-${d.room.id}-${d.side}`"
                type="button"
                class="door absolute flex -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full border-2 border-[var(--wood-edge)] font-bold shadow-[0_2px_0_rgb(0_0_0/0.5)]"
                :class="[
                    doorKinds[d.door.kind]?.tone ?? 'bg-white',
                    d.mine && interactive ? 'cursor-pointer hover:scale-110' : 'pointer-events-none scale-75 opacity-90',
                ]"
                :style="{
                    left: `${d.x}px`,
                    top: `${d.y}px`,
                    width: `${Math.max(26, unit * 0.36)}px`,
                    height: `${Math.max(26, unit * 0.36)}px`,
                    fontSize: `${Math.max(12, unit * 0.18)}px`,
                }"
                :title="`${sideNames[d.side]}: ${doorKinds[d.door.kind]?.label ?? d.door.kind}`"
                :aria-label="`${sideNames[d.side]}: ${doorKinds[d.door.kind]?.label ?? d.door.kind}`"
                :disabled="!(d.mine && interactive)"
                @click="emit('door', d.door, d.side)"
            >
                {{ doorKinds[d.door.kind]?.icon ?? '?' }}
            </button>
        </div>

        <div class="absolute right-2 top-2 flex rounded-md border-2 border-[var(--wood-edge)] bg-[#2b1d12]/90 p-0.5 text-xs font-bold text-amber-50">
            <button type="button" class="rounded px-2 py-1" :class="!whole ? 'bg-[var(--gold)] text-[var(--wood-edge)]' : ''" @click="whole = false">
                Nah
            </button>
            <button type="button" class="rounded px-2 py-1" :class="whole ? 'bg-[var(--gold)] text-[var(--wood-edge)]' : ''" @click="whole = true">
                Ganze Karte
            </button>
        </div>
    </div>
</template>

<style scoped>
.fog {
    filter: grayscale(1) brightness(1.6);
    opacity: 0.55;
    animation: drift 4s ease-in-out infinite;
}
@keyframes drift {
    50% {
        transform: translate(-50%, -58%);
    }
}
.door {
    transition: transform 0.12s;
}
</style>
