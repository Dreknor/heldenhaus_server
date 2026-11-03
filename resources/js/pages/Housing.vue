<script setup lang="ts">
import Emoji from '@/components/game/Emoji.vue';
import ItemIcon from '@/components/game/ItemIcon.vue';
import RoomEditor, { type Furniture, type Room } from '@/components/game/RoomEditor.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Boxes, Hammer, Sprout, Trash2, Trophy } from 'lucide-vue-next';
import { ref } from 'vue';

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

const props = defineProps<{ home: HousingData; furniture: Furniture[]; visitor: boolean; alliedFamilies: { id: number; name: string }[] }>();
const selectedRoom = ref(props.home.rooms[0]?.id ?? 0);
const gardenPlants = ref<Record<number, string>>({});
const contribution = ref<Record<number, { item: string; amount: number; from_storage: boolean }>>({});
const showProjectForm = ref(false);
const rewardText = ref('');
const guestbookKind = ref<'text' | 'preset' | 'emoji'>('preset');
const guestbookBody = ref('');

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
                class="relative overflow-hidden rounded-3xl border-2 border-emerald-200 bg-gradient-to-b from-sky-200 via-sky-100 to-emerald-100 p-5 dark:border-emerald-900 dark:from-slate-900 dark:via-slate-900 dark:to-emerald-950/50 sm:p-6"
            >
                <div
                    class="absolute inset-x-0 bottom-0 h-[38%] bg-gradient-to-b from-[#8bcf5a] to-[#5fae42] dark:from-[#2f5d3a] dark:to-[#1c3f28]"
                    aria-hidden="true"
                />
                <img
                    src="/images/hof/house.png"
                    alt=""
                    class="absolute bottom-3 right-4 w-36 drop-shadow-lg [image-rendering:pixelated] sm:right-8 sm:w-44"
                    aria-hidden="true"
                />
                <img
                    src="/images/hof/apple-tree-small.png"
                    alt=""
                    class="absolute bottom-4 right-44 hidden w-20 [image-rendering:pixelated] sm:right-64 sm:block"
                    aria-hidden="true"
                />
                <img
                    src="/images/hof/flowers-1.png"
                    alt=""
                    class="absolute bottom-3 right-36 w-6 [image-rendering:pixelated] sm:right-56"
                    aria-hidden="true"
                />
                <div class="relative max-w-[60%] pb-14 sm:pb-16">
                    <p class="text-xs font-black uppercase tracking-widest text-emerald-800 dark:text-emerald-300">{{ home.family.name }}</p>
                    <h1 class="text-2xl font-black tracking-tight sm:text-3xl">Familienhaus</h1>
                    <p class="mt-1 inline-block rounded-full bg-white/80 px-3 py-0.5 text-sm font-bold shadow-sm dark:bg-black/30">
                        {{ home.stage_name }}
                    </p>
                    <p v-if="visitor" class="mt-2 text-sm font-semibold text-amber-800 dark:text-amber-200">Zu Besuch – ansehen und Grüße dalassen</p>
                </div>
                <div
                    class="absolute bottom-3 left-4 flex items-center gap-2 rounded-2xl bg-white/85 px-3 py-1.5 text-sm font-bold shadow dark:bg-black/40"
                    :title="`${home.clutter.overdue} überfällige Aufgaben`"
                >
                    <span>Ordnung</span>
                    <span class="flex gap-0.5" aria-hidden="true">
                        <span
                            v-for="i in 3"
                            :key="i"
                            class="h-2.5 w-5 rounded-full"
                            :class="i <= 3 - home.clutter.level ? 'bg-emerald-500' : 'bg-slate-300 dark:bg-slate-600'"
                        />
                    </span>
                    <span class="text-xs font-semibold text-muted-foreground">{{
                        home.clutter.level === 0 ? 'alles aufgeräumt' : `${home.clutter.overdue} Aufgaben offen`
                    }}</span>
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

            <section class="grid gap-3">
                <nav v-if="home.rooms.length > 1" class="flex gap-2 overflow-x-auto pb-1" aria-label="Zimmer">
                    <button
                        v-for="room in home.rooms"
                        :key="room.id"
                        class="shrink-0 rounded-xl px-4 py-2 text-sm font-bold transition"
                        :class="selectedRoom === room.id ? 'bg-amber-500 text-white shadow' : 'border-2 bg-card hover:bg-muted'"
                        @click="selectedRoom = room.id"
                    >
                        {{ room.name }}
                    </button>
                </nav>
                <template v-for="room in home.rooms" :key="room.id">
                    <RoomEditor v-if="selectedRoom === room.id" :room="room" :furniture="furniture" :styles="home.styles" />
                </template>
            </section>

            <section class="grid gap-4 lg:grid-cols-2">
                <Card v-if="!visitor" class="lg:col-span-2">
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2"><Hammer class="size-5" /> Bauprojekte</CardTitle>
                        <CardDescription>Der aktuelle Stand gemeinsamer Bauvorhaben.</CardDescription>
                    </CardHeader>
                    <CardContent class="grid gap-4">
                        <div v-if="home.can_start_projects && home.projects.available.length">
                            <Button variant="outline" size="sm" @click="showProjectForm = !showProjectForm">
                                {{ showProjectForm ? 'Projektliste schließen' : 'Bauprojekt starten' }}
                            </Button>
                            <div v-if="showProjectForm" class="mt-3 grid gap-3 rounded-xl border p-3 md:grid-cols-2 xl:grid-cols-3">
                                <Input
                                    v-model="rewardText"
                                    maxlength="120"
                                    placeholder="Gemeinsame Belohnung (optional)"
                                    class="md:col-span-2 xl:col-span-3"
                                />
                                <div
                                    v-for="project in home.projects.available"
                                    :key="project.key"
                                    class="flex flex-wrap items-center gap-3 rounded-2xl border-2 bg-card p-3"
                                >
                                    <ItemIcon :item="{ key: `proj_${project.key}`, type: 'project' }" :size="56" />
                                    <div class="min-w-0 flex-1">
                                        <p class="font-extrabold">{{ project.name }}</p>
                                        <p v-if="project.description" class="text-xs text-muted-foreground">{{ project.description }}</p>
                                        <p class="mt-1.5 flex flex-wrap gap-1.5 text-xs font-bold">
                                            <span
                                                v-for="item in project.items"
                                                :key="item.key"
                                                class="flex items-center gap-1 rounded-full bg-muted py-0.5 pl-0.5 pr-2"
                                            >
                                                <ItemIcon :item="{ key: item.key, type: 'material' }" :size="20" /> {{ item.quantity }}×
                                                {{ item.name }}
                                            </span>
                                            <span v-if="project.gold" class="rounded-full bg-amber-100 px-2 py-0.5 text-amber-800"
                                                >{{ project.gold }} Gold</span
                                            >
                                        </p>
                                    </div>
                                    <Button size="sm" @click="startProject(project.key)">Starten</Button>
                                </div>
                            </div>
                        </div>
                        <div v-for="project in home.projects.active" :key="project.id" class="rounded-xl border p-3">
                            <div class="mb-3 flex items-center gap-3 text-lg font-extrabold">
                                <ItemIcon :item="{ key: `proj_${project.key}`, type: 'project' }" :size="44" />{{ project.name }}
                            </div>
                            <div v-for="item in project.items" :key="item.name" class="mb-2 text-sm">
                                <div class="mb-1 flex justify-between gap-2">
                                    <span class="flex items-center gap-1.5"
                                        ><ItemIcon :item="{ key: item.key, type: 'material' }" :size="22" />{{ item.name }}</span
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
                        <form class="grid gap-2 sm:grid-cols-[7.5rem_minmax(0,1fr)_auto]" @submit.prevent="saveGuestbook">
                            <select v-model="guestbookKind" class="h-10 min-w-0 rounded-md border border-input bg-background px-2 text-sm">
                                <option value="preset">Gruß</option>
                                <option value="emoji">Emoji</option>
                                <option v-if="home.can_write_text" value="text">Text</option>
                            </select>
                            <select
                                v-if="guestbookKind === 'preset'"
                                v-model="guestbookBody"
                                required
                                class="h-10 w-full min-w-0 rounded-md border border-input bg-background px-2 text-sm"
                            >
                                <option value="">Gruß wählen</option>
                                <option v-for="(text, key) in home.presets" :key="key" :value="key">{{ text }}</option>
                            </select>
                            <select
                                v-else-if="guestbookKind === 'emoji'"
                                v-model="guestbookBody"
                                required
                                class="h-10 w-full min-w-0 rounded-md border border-input bg-background px-2 text-sm"
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
                                <Emoji :char="item.icon" :size="22" class="mr-0.5" /> {{ item.name }} × {{ item.quantity }}
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
                                    <Emoji :char="boss.icon" :size="22" class="mr-0.5" /> {{ boss.name }}
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
                                    <Emoji :char="item.icon" :size="22" class="mr-0.5" /> {{ item.name }}
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
