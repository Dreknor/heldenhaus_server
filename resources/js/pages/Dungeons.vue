<script setup lang="ts">
import DungeonMap from '@/components/game/DungeonMap.vue';
import GamePanel from '@/components/game/GamePanel.vue';
import MonsterArt, { type MonsterArtConfig } from '@/components/game/MonsterArt.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import type { AvatarConfig, Equipment } from '@/lib/avatar';
import type { DungeonArt } from '@/lib/dungeonMap';
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

type Door = { to: number; kind: string; sprite: string } | null;
type Room = {
    id: number;
    x: number;
    y: number;
    type?: string;
    block?: string;
    fog: boolean;
    doors?: Record<'N' | 'E' | 'S' | 'W', Door>;
    monster?: { key: string; name: string; icon: string; hp: number; max_hp: number; art: MonsterArtConfig | null };
    chest?: { opened: boolean };
    trap?: { sprung: boolean };
    puzzle?: { question: string; solved: boolean; hint: string | null };
    campfire?: { used: number[] };
    lever?: { pulled: boolean };
    offers?: { key: string; price: number; name: string; icon: string }[];
};
type Dungeon = {
    id: number;
    kind: 'family' | 'solo';
    theme: { key: string; name: string; icon: string };
    mode: string;
    status: string;
    boss_unlocked: boolean;
    rooms: Room[];
    edges: { a: number; b: number; kind: string }[];
    members: {
        user_id: number;
        name: string;
        room: number;
        hp: number;
        class: string | null;
        appearance?: { avatar?: Partial<AvatarConfig>; seed?: number; equipment?: Equipment; class_key?: string | null } | null;
    }[];
    me: { room: number; hp: number; max_hp: number; torches: number; torch_daily_max: number; class: string | null };
    last_action_id: number | null;
    art: DungeonArt;
};
type LogEntry = { id: number; user: string; action: string; room: number; events: string[]; at: string };

const props = defineProps<{
    familyDungeon: Dungeon | null;
    soloDungeon: Dungeon | null;
    familyUnlocked: boolean;
    soloUnlocked: boolean;
    familyUnlockLevel: number;
    soloUnlockLevel: number;
    stats: { mp: number; class: string | null };
    inventory: {
        potions: number;
        keys: number;
        potionItemId: number | null;
        keyItemId: number | null;
        healingPotions: { id: number; name: string; icon: string; count: number }[];
    };
    currentUserId: number;
    logs: LogEntry[];
    soloLogs: LogEntry[];
    selectedTab: string;
    lastResult: { events?: string[]; loot?: Record<string, number>; damage?: number; boss_damage?: number } | null;
    merchantOffers: { key: string; name: string; icon: string; price: number }[];
}>();

const page = usePage();
const selectedTab = ref<'family' | 'solo'>(props.selectedTab === 'solo' ? 'solo' : 'family');
const answer = ref('');
const pending = ref(false);
const dungeon = computed(() => (selectedTab.value === 'solo' ? props.soloDungeon : props.familyDungeon));
const rooms = computed(() => dungeon.value?.rooms ?? []);
const currentRoom = computed(() => rooms.value.find((room) => room.id === dungeon.value?.me.room));
const currentMonster = computed(() => currentRoom.value?.monster);
const monsterAlive = computed(() => Boolean(currentMonster.value && currentMonster.value.hp > 0));
const problem = computed(() => (page.props.errors as Record<string, string> | undefined)?.dungeon);
const logEntries = computed(() => (selectedTab.value === 'solo' ? props.soloLogs : props.logs));
const roomNames: Record<string, string> = {
    entrance: 'Eingang',
    monster: 'Monsterraum',
    elite: 'Elite',
    boss: 'Endboss',
    treasure: 'Schatzkammer',
    trap: 'Falle',
    puzzle: 'Runenrätsel',
    campfire: 'Lagerfeuer',
    lever: 'Mechanismus',
    merchant: 'Wandernder Händler',
};
const roomIcons: Record<string, string> = {
    entrance: '🚪',
    monster: '⚔️',
    elite: '💀',
    boss: '👑',
    treasure: '🧰',
    trap: '🕸️',
    puzzle: '🔮',
    campfire: '🔥',
    lever: '⚙️',
    merchant: '🧙',
};
const doorNames: Record<string, string> = { open: 'Offener Durchgang', locked: 'Verschlossene Tür', gate: 'Tor', cracked: 'Rissige Wand' };
const actionNames: Record<string, string> = {
    move: 'Erkundet einen Raum',
    fight: 'Kämpft',
    interact: 'Untersucht etwas',
    'use-item': 'Benutzt einen Gegenstand',
};
const directionNames: Record<string, string> = { N: 'Norden', E: 'Osten', S: 'Süden', W: 'Westen' };
function act(action: string, data: Record<string, string | number> = {}) {
    if (!dungeon.value || pending.value) return;
    pending.value = true;
    router.post(
        route('dungeons.act', { dungeon: dungeon.value.id, action }),
        { ...data, client_uuid: crypto.randomUUID() },
        {
            preserveScroll: true,
            onFinish: () => {
                pending.value = false;
                answer.value = '';
            },
        },
    );
}

function move(to: number) {
    act('move', { to });
}

function useKey(to: number) {
    if (props.inventory.keyItemId) act('use-item', { item_id: props.inventory.keyItemId, to });
}

function usePotion(itemId: number) {
    act('use-item', { item_id: itemId });
}

function startSolo() {
    router.post(route('dungeons.solo'), {}, { preserveScroll: true, onSuccess: () => (selectedTab.value = 'solo') });
}

function interact(data: Record<string, string | number> = {}) {
    act('interact', data);
}

const doorHint = ref<string | null>(null);

/** Tür auf der Karte angetippt */
function onDoor(door: { to: number; kind: string }) {
    doorHint.value = null;
    if (door.kind === 'open' && (dungeon.value?.me.torches ?? 0) > 0) move(door.to);
    else if (door.kind === 'locked' && props.inventory.keys && props.inventory.keyItemId) useKey(door.to);
    else if (door.kind === 'cracked' && props.stats.class === 'warrior') interact({ to: door.to });
    else
        doorHint.value =
            door.kind === 'open'
                ? 'Deine Fackeln sind aus. Erledige Aufgaben, um neue zu bekommen.'
                : door.kind === 'gate'
                  ? 'Das Tor ist zu. Irgendwo muss ein Hebel sein.'
                  : door.kind === 'cracked'
                    ? 'Die Wand bröckelt. Nur ein Krieger kann sie einschlagen.'
                    : 'Verschlossen. Du brauchst einen Schlüssel.';
}

function doorLabel(kind: string, target: number) {
    return `${doorNames[kind] ?? kind} · Raum ${target + 1}`;
}
</script>

<template>
    <Head title="Dungeon" />

    <AppLayout :breadcrumbs="[{ title: 'Dungeon', href: '/dungeons' }]">
        <main class="mx-auto flex w-full min-w-0 max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
            <header class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <p class="text-sm font-bold uppercase tracking-[0.18em] text-amber-700 dark:text-amber-400">Abenteuer wartet</p>
                    <h1 class="text-3xl font-black tracking-tight">Dungeon</h1>
                    <p class="mt-1 max-w-2xl text-sm text-muted-foreground">
                        Erkundet Raum für Raum die Gruft. Jeder Zug kostet Fackeln – Kämpfe, Rätsel und Schätze verändern den Dungeon für alle.
                    </p>
                </div>
                <div class="hh-wood flex rounded-md border-2 border-[var(--wood-edge)] p-1" role="tablist" aria-label="Dungeon auswählen">
                    <button
                        class="rounded px-4 py-2 text-sm font-bold transition"
                        :class="selectedTab === 'family' ? 'bg-[var(--gold)] text-[var(--wood-edge)]' : 'text-amber-50/80 hover:bg-black/20'"
                        role="tab"
                        :aria-selected="selectedTab === 'family'"
                        @click="selectedTab = 'family'"
                    >
                        👨‍👩‍👧 Familie
                    </button>
                    <button
                        class="rounded px-4 py-2 text-sm font-bold transition"
                        :class="selectedTab === 'solo' ? 'bg-[var(--gold)] text-[var(--wood-edge)]' : 'text-amber-50/80 hover:bg-black/20'"
                        role="tab"
                        :aria-selected="selectedTab === 'solo'"
                        @click="selectedTab = 'solo'"
                    >
                        🧭 Solo
                    </button>
                </div>
            </header>

            <div
                v-if="problem"
                class="rounded-xl border border-rose-300 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-200"
                role="alert"
            >
                {{ problem }}
            </div>
            <section
                v-if="lastResult?.events?.length"
                class="rounded-xl border border-emerald-300 bg-emerald-50 p-4 dark:border-emerald-900 dark:bg-emerald-950/30"
                aria-live="polite"
            >
                <p class="mb-1 font-extrabold text-emerald-900 dark:text-emerald-100">Dein letzter Zug</p>
                <p v-for="(event, index) in lastResult.events" :key="index" class="text-sm">{{ event }}</p>
                <p v-if="lastResult.loot" class="mt-2 text-sm font-bold">
                    Beute:
                    {{
                        Object.entries(lastResult.loot)
                            .map(([key, amount]) => `${amount}× ${key.replaceAll('_', ' ')}`)
                            .join(' · ')
                    }}
                </p>
                <p v-else-if="lastResult.damage" class="mt-2 text-sm font-bold">Schaden: {{ lastResult.damage }} HP</p>
            </section>

            <section v-if="selectedTab === 'solo' && !soloUnlocked" class="rounded-2xl border bg-card p-6">
                <p class="text-4xl">🔒</p>
                <h2 class="mt-2 text-xl font-extrabold">Solo-Gewölbe noch versiegelt</h2>
                <p class="mt-1 text-sm text-muted-foreground">Ab Level {{ soloUnlockLevel }} wird diese Tür für dich geöffnet.</p>
            </section>
            <section v-else-if="selectedTab === 'family' && !familyUnlocked && !familyDungeon" class="rounded-2xl border bg-card p-6">
                <p class="text-4xl">🔒</p>
                <h2 class="mt-2 text-xl font-extrabold">Familien-Dungeon noch versiegelt</h2>
                <p class="mt-1 text-sm text-muted-foreground">Ab Level {{ familyUnlockLevel }} könnt ihr gemeinsam losziehen.</p>
            </section>
            <section v-else-if="selectedTab === 'solo' && !soloDungeon" class="flex flex-col items-start gap-3 rounded-2xl border bg-card p-6">
                <p class="text-4xl">🗝️</p>
                <div>
                    <h2 class="text-xl font-extrabold">Ein kleines Gewölbe wartet</h2>
                    <p class="mt-1 max-w-xl text-sm text-muted-foreground">
                        Drei Räume, ein Schatz. Das Solo-Gewölbe öffnet sich, wenn deine fälligen Dailies für heute erledigt sind.
                    </p>
                </div>
                <Button @click="startSolo">Solo-Gewölbe betreten</Button>
            </section>
            <section v-else-if="dungeon" class="grid min-w-0 gap-5 xl:grid-cols-[minmax(0,1.45fr)_minmax(20rem,0.85fr)]">
                <div class="min-w-0 space-y-5">
                    <GamePanel
                        tone="wood"
                        :title="`${dungeon.theme.icon} ${dungeon.theme.name}`"
                        :subtitle="dungeon.kind === 'family' ? 'Gemeinsames Abenteuer' : 'Dein Solo-Abenteuer'"
                    >
                        <template #action>
                            <span class="hh-button shrink-0 border-[var(--wood-edge)] bg-black/30 px-2 py-0.5 text-xs text-amber-50">
                                {{ dungeon.status === 'active' ? '🟢 Läuft' : dungeon.status === 'completed' ? '🏆 Geschafft' : '⌛ Vorbei' }}
                            </span>
                        </template>
                        <DungeonMap
                            :key="dungeon.id"
                            :rooms="rooms"
                            :edges="dungeon.edges"
                            :members="dungeon.members"
                            :art="dungeon.art"
                            :my-room="dungeon.me.room"
                            :my-id="currentUserId"
                            :room-icons="roomIcons"
                            :room-names="roomNames"
                            :interactive="dungeon.status === 'active' && !pending"
                            @door="onDoor"
                            @monster="act('fight', { move: 'attack' })"
                        />
                        <p v-if="doorHint" class="rounded-md bg-black/30 px-3 py-2 text-xs font-semibold text-amber-100" aria-live="polite">
                            {{ doorHint }}
                        </p>
                        <div class="grid grid-cols-3 gap-2">
                            <div class="hud">
                                <span class="text-xl">❤️</span>
                                <div class="min-w-0 flex-1">
                                    <p class="hud-label">HP</p>
                                    <div class="mt-1 h-2.5 overflow-hidden rounded-sm border border-black/60 bg-black/50">
                                        <span
                                            class="block h-full bg-rose-500"
                                            :style="{ width: `${Math.max(0, Math.min(100, (dungeon.me.hp / dungeon.me.max_hp) * 100))}%` }"
                                        />
                                    </div>
                                </div>
                                <span class="font-pixel tabular-nums">{{ dungeon.me.hp }}/{{ dungeon.me.max_hp }}</span>
                            </div>
                            <div class="hud">
                                <span class="text-xl">🔥</span>
                                <p class="hud-label flex-1">Fackeln</p>
                                <span class="font-pixel tabular-nums">{{ dungeon.me.torches }}</span>
                            </div>
                            <div class="hud">
                                <span class="text-xl">🧭</span>
                                <p class="hud-label flex-1">Räume</p>
                                <span class="font-pixel tabular-nums">{{ rooms.filter((room) => !room.fog).length }}</span>
                            </div>
                        </div>
                        <p class="text-xs text-[var(--panel-sub)]">
                            Tippe auf eine Tür, um weiterzugehen, oder auf das Monster, um anzugreifen. Räume mit ❔ liegen noch im Nebel.
                        </p>
                    </GamePanel>
                </div>

                <aside class="min-w-0 space-y-5">
                    <section class="rounded-2xl border bg-card p-5">
                        <div class="mb-4 flex items-start justify-between gap-3">
                            <div>
                                <p class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Du bist in</p>
                                <h2 class="text-xl font-black">{{ currentRoom ? roomNames[currentRoom.type ?? ''] : 'Nebel' }}</h2>
                            </div>
                            <span class="text-4xl">{{ roomIcons[currentRoom?.type ?? ''] ?? '🌫️' }}</span>
                        </div>
                        <template v-if="currentRoom">
                            <div
                                v-if="currentMonster"
                                class="mb-4 rounded-xl bg-gradient-to-br from-rose-50 to-orange-50 p-3 dark:from-rose-950/30 dark:to-orange-950/20"
                            >
                                <div class="flex items-center gap-3">
                                    <MonsterArt :art="currentMonster.art" :size="96" :defeated="!monsterAlive" class="shrink-0" />
                                    <div class="min-w-0 flex-1">
                                        <p class="font-extrabold">{{ currentMonster.name }}</p>
                                        <p class="text-xs text-muted-foreground">
                                            {{ monsterAlive ? 'Der Weg ist versperrt.' : 'Besiegt – der Weg ist frei.' }}
                                        </p>
                                        <div class="mt-2 h-2 overflow-hidden rounded-full bg-black/10">
                                            <div
                                                class="h-full rounded-full bg-rose-500 transition-all"
                                                :style="{ width: `${Math.max(0, (currentMonster.hp / currentMonster.max_hp) * 100)}%` }"
                                            />
                                        </div>
                                        <p class="mt-1 text-xs font-bold tabular-nums">{{ currentMonster.hp }} / {{ currentMonster.max_hp }} HP</p>
                                    </div>
                                </div>
                                <div v-if="monsterAlive && dungeon.status === 'active'" class="mt-3 flex flex-wrap gap-2">
                                    <Button :disabled="pending || dungeon.me.torches < 1" @click="act('fight', { move: 'attack' })"
                                        >⚔️ Angreifen</Button
                                    >
                                    <Button
                                        variant="outline"
                                        :disabled="pending || dungeon.me.torches < 1 || stats.mp < 5"
                                        @click="act('fight', { move: 'skill' })"
                                        >✨ Fähigkeit · 5 MP</Button
                                    >
                                    <Button
                                        v-if="inventory.potions"
                                        variant="outline"
                                        :disabled="pending || dungeon.me.torches < 1"
                                        @click="act('fight', { move: 'potion' })"
                                        >🧪 Trank im Kampf · {{ inventory.potions }}</Button
                                    >
                                </div>
                                <p
                                    v-if="currentRoom.type === 'boss' && !dungeon.boss_unlocked && monsterAlive"
                                    class="mt-3 rounded-lg bg-amber-100 px-3 py-2 text-xs font-semibold text-amber-950 dark:bg-amber-900/40 dark:text-amber-100"
                                >
                                    Der Boss wartet, bis die Familie zusammenkommt und ihn freischaltet.
                                </p>
                            </div>

                            <div v-if="currentRoom.chest" class="mb-3 rounded-xl bg-amber-50 p-3 text-sm dark:bg-amber-950/30">
                                <p class="font-bold">{{ currentRoom.chest.opened ? 'Die Truhe ist leer.' : 'Eine Truhe wartet auf dich.' }}</p>
                                <Button
                                    v-if="!currentRoom.chest.opened && dungeon.status === 'active'"
                                    class="mt-2"
                                    :disabled="pending"
                                    @click="interact()"
                                    >🧰 Truhe öffnen</Button
                                >
                            </div>
                            <div v-if="currentRoom.puzzle" class="mb-3 rounded-xl bg-sky-50 p-3 dark:bg-sky-950/30">
                                <p class="font-bold">{{ currentRoom.puzzle.solved ? '✅ Rätsel gelöst' : currentRoom.puzzle.question }}</p>
                                <p v-if="currentRoom.puzzle.hint && !currentRoom.puzzle.solved" class="mt-1 text-xs text-muted-foreground">
                                    Hinweis: {{ currentRoom.puzzle.hint }}
                                </p>
                                <form
                                    v-if="!currentRoom.puzzle.solved && dungeon.status === 'active'"
                                    class="mt-2 flex gap-2"
                                    @submit.prevent="interact({ answer })"
                                >
                                    <Input
                                        v-model="answer"
                                        inputmode="numeric"
                                        autocomplete="off"
                                        placeholder="Deine Antwort"
                                        aria-label="Antwort auf das Rätsel"
                                    />
                                    <Button type="submit" :disabled="pending || !answer">Prüfen</Button>
                                </form>
                            </div>
                            <div v-if="currentRoom.trap" class="mb-3 rounded-xl bg-orange-50 p-3 text-sm dark:bg-orange-950/30">
                                <p class="font-bold">{{ currentRoom.trap.sprung ? 'Die Falle wurde ausgelöst.' : 'Hier lauert eine Falle.' }}</p>
                                <Button
                                    v-if="!currentRoom.trap.sprung && dungeon.status === 'active'"
                                    class="mt-2"
                                    variant="outline"
                                    :disabled="pending"
                                    @click="interact()"
                                    >Falle entschärfen</Button
                                >
                            </div>
                            <div v-if="currentRoom.campfire" class="mb-3 rounded-xl bg-orange-50 p-3 text-sm dark:bg-orange-950/30">
                                <p class="font-bold">Ein Lagerfeuer spendet Wärme und heilt dich.</p>
                                <Button class="mt-2" variant="outline" :disabled="pending || dungeon.status !== 'active'" @click="interact()"
                                    >🔥 Am Feuer ausruhen</Button
                                >
                            </div>
                            <div v-if="currentRoom.lever" class="mb-3 rounded-xl bg-indigo-50 p-3 text-sm dark:bg-indigo-950/30">
                                <p class="font-bold">
                                    {{ currentRoom.lever.pulled ? 'Der Mechanismus wurde ausgelöst.' : 'Ein geheimnisvoller Hebel.' }}
                                </p>
                                <Button
                                    v-if="!currentRoom.lever.pulled && dungeon.status === 'active'"
                                    class="mt-2"
                                    variant="outline"
                                    :disabled="pending"
                                    @click="interact()"
                                    >⚙️ Hebel ziehen</Button
                                >
                            </div>
                            <div v-if="currentRoom.type === 'merchant'" class="mb-3 rounded-xl bg-violet-50 p-3 dark:bg-violet-950/30">
                                <p class="font-bold">Der Händler bietet nützliche Dinge an.</p>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    <Button
                                        v-for="offer in merchantOffers"
                                        :key="offer.key"
                                        variant="outline"
                                        :disabled="pending || dungeon.status !== 'active'"
                                        @click="interact({ buy: offer.key })"
                                        >{{ offer.icon }} {{ offer.name }} · {{ offer.price }} Gold</Button
                                    >
                                </div>
                            </div>
                        </template>
                        <div
                            v-if="
                                inventory.healingPotions.some((potion) => potion.count > 0) &&
                                dungeon.status === 'active' &&
                                dungeon.me.hp < dungeon.me.max_hp
                            "
                            class="mb-4 flex flex-wrap gap-2"
                        >
                            <Button
                                v-for="potion in inventory.healingPotions.filter((item) => item.count > 0)"
                                :key="potion.id"
                                variant="outline"
                                :disabled="pending"
                                @click="usePotion(potion.id)"
                            >
                                {{ potion.icon }} {{ potion.name }} · {{ potion.count }}
                            </Button>
                        </div>
                        <div v-if="currentRoom?.doors && dungeon.status === 'active'" class="space-y-2 border-t pt-4">
                            <p class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Ausgänge</p>
                            <template v-for="(door, side) in currentRoom.doors" :key="side">
                                <div v-if="door" class="flex flex-wrap items-center gap-2 rounded-lg border p-2">
                                    <span class="min-w-0 flex-1 text-xs font-semibold"
                                        >{{ directionNames[side] }} · {{ doorLabel(door.kind, door.to) }}</span
                                    >
                                    <Button
                                        v-if="door.kind === 'open'"
                                        size="sm"
                                        variant="outline"
                                        :disabled="pending || dungeon.me.torches < 1"
                                        @click="move(door.to)"
                                        >Betreten</Button
                                    >
                                    <Button
                                        v-else-if="door.kind === 'locked' && inventory.keys && inventory.keyItemId"
                                        size="sm"
                                        variant="outline"
                                        :disabled="pending"
                                        @click="useKey(door.to)"
                                        >🗝️ Aufschließen</Button
                                    >
                                    <Button
                                        v-else-if="door.kind === 'cracked' && stats.class === 'warrior'"
                                        size="sm"
                                        variant="outline"
                                        :disabled="pending"
                                        @click="interact({ to: door.to })"
                                        >Wand einschlagen</Button
                                    >
                                    <span v-else class="text-[10px] text-muted-foreground">{{
                                        door.kind === 'gate'
                                            ? 'Hebel suchen'
                                            : door.kind === 'cracked'
                                              ? 'Nur ein Krieger kommt hindurch'
                                              : 'Schlüssel finden'
                                    }}</span>
                                </div>
                            </template>
                        </div>
                    </section>

                    <section v-if="dungeon.kind === 'family' && dungeon.members.length" class="rounded-2xl border bg-card p-4">
                        <h3 class="mb-3 font-extrabold">Mit auf Expedition</h3>
                        <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-1">
                            <div
                                v-for="member in dungeon.members"
                                :key="member.user_id"
                                class="flex items-center gap-2 rounded-lg bg-muted/50 px-3 py-2 text-sm"
                            >
                                <span>{{ member.user_id === currentUserId ? '🧙' : '🧝' }}</span>
                                <span class="min-w-0 flex-1 truncate font-semibold">{{ member.name }}</span>
                                <span class="text-xs text-muted-foreground">Raum {{ member.room + 1 }} · {{ member.hp }} HP</span>
                            </div>
                        </div>
                    </section>

                    <section class="rounded-2xl border bg-card p-4">
                        <div class="mb-3 flex items-center justify-between gap-2">
                            <h3 class="font-extrabold">Expeditionsprotokoll</h3>
                            <span class="text-xs text-muted-foreground">{{ logEntries.length }} letzte Züge</span>
                        </div>
                        <ol v-if="logEntries.length" class="space-y-2">
                            <li v-for="entry in [...logEntries].reverse()" :key="entry.id" class="rounded-lg bg-muted/50 px-3 py-2 text-xs">
                                <p class="font-bold">
                                    {{ entry.user }} · {{ actionNames[entry.action] ?? entry.action }} · Raum {{ entry.room + 1 }}
                                </p>
                                <p v-for="(event, index) in entry.events" :key="index" class="mt-1 text-muted-foreground">{{ event }}</p>
                            </li>
                        </ol>
                        <p v-else class="text-sm text-muted-foreground">Noch keine Spuren im Staub. Der erste Zug gehört dir.</p>
                    </section>
                </aside>
            </section>
        </main>
    </AppLayout>
</template>

<style scoped>
.hud {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    border: 2px solid var(--wood-edge);
    border-radius: 0.375rem;
    background: rgb(0 0 0 / 0.28);
    padding: 0.4rem 0.6rem;
    min-width: 0;
    flex-wrap: wrap;
    box-shadow: inset 0 0 0 1px rgb(255 220 160 / 0.12);
}
@media (max-width: 640px) {
    .hud {
        flex-direction: column;
        justify-content: center;
        gap: 0.2rem;
        text-align: center;
    }
    .hud > div {
        width: 100%;
    }
}
.hud-label {
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: var(--panel-sub);
}
</style>
