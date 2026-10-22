<script setup lang="ts">
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';
import { Boxes, Hammer, House, Sprout } from 'lucide-vue-next';

interface Room {
    id: number;
    name: string;
    type: string;
    width: number;
    height: number;
    items: { id: number; name: string; icon: string; x: number; y: number }[];
}

interface HousingData {
    family: { name: string };
    stage_name: string;
    rooms: Room[];
    clutter: { level: number; overdue: number };
    projects: {
        active: {
            id: number;
            name: string;
            icon: string;
            status: string;
            finishes_at: string | null;
            items: { name: string; needed: number; have: number }[];
            gold: { needed: number; have: number };
        }[];
    };
    garden: {
        plants: { key: string; name: string }[];
        plots: { slot: number; plant_key: string | null; stage: 'empty' | 'thirsty' | 'growing' | 'ready'; waterings: number; ready_at: string | null }[];
    } | null;
    storage: { name: string; icon: string; quantity: number }[];
}

const props = defineProps<{ home: HousingData }>();

const gardenStage = {
    empty: 'Frei',
    thirsty: 'Braucht Wasser',
    growing: 'Wächst',
    ready: 'Erntereif',
} as const;

const dateTime = (value: string) =>
    new Intl.DateTimeFormat('de-DE', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value));

const plantName = (key: string | null) => props.home.garden?.plants.find((plant) => plant.key === key)?.name ?? key;
</script>

<template>
    <Head title="Familienhaus" />

    <AppLayout :breadcrumbs="[{ title: 'Familienhaus', href: '/housing' }]">
        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <header class="flex flex-wrap items-center gap-4 rounded-2xl border bg-gradient-to-br from-amber-50 to-orange-100 p-5 dark:from-amber-950/40 dark:to-orange-950/30">
                <div class="grid size-14 place-items-center rounded-2xl bg-white/70 text-amber-700 dark:bg-black/20 dark:text-amber-300">
                    <House class="size-8" />
                </div>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold text-muted-foreground">{{ home.family.name }}</p>
                    <h1 class="text-2xl font-extrabold tracking-tight">Familienhaus</h1>
                    <p class="text-sm text-muted-foreground">{{ home.stage_name }}</p>
                </div>
                <div class="rounded-xl bg-white/70 px-4 py-2 text-sm dark:bg-black/20">
                    <span class="font-bold">Unordnung: {{ home.clutter.level }}/3</span>
                    <span class="ml-2 text-muted-foreground">({{ home.clutter.overdue }} überfällige Aufgaben)</span>
                </div>
            </header>

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
                    </CardContent>
                </Card>
            </section>

            <section class="grid gap-4 lg:grid-cols-2">
                <Card>
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2"><Hammer class="size-5" /> Bauprojekte</CardTitle>
                        <CardDescription>Der aktuelle Stand gemeinsamer Bauvorhaben.</CardDescription>
                    </CardHeader>
                    <CardContent class="grid gap-4">
                        <div v-for="project in home.projects.active" :key="project.id" class="rounded-xl border p-3">
                            <div class="mb-3 flex items-center gap-2 font-bold"><span>{{ project.icon }}</span>{{ project.name }}</div>
                            <div v-for="item in project.items" :key="item.name" class="mb-2 text-sm">
                                <div class="mb-1 flex justify-between gap-2">
                                    <span>{{ item.name }}</span><span class="text-muted-foreground">{{ item.have }}/{{ item.needed }}</span>
                                </div>
                                <div class="h-2 overflow-hidden rounded-full bg-muted">
                                    <div class="h-full rounded-full bg-amber-500" :style="{ width: `${Math.min(100, (item.have / item.needed) * 100)}%` }" />
                                </div>
                            </div>
                            <p class="text-sm text-muted-foreground">Gold: {{ project.gold.have }}/{{ project.gold.needed }}</p>
                            <p v-if="project.finishes_at" class="mt-2 text-xs text-muted-foreground">Fertigstellung: {{ dateTime(project.finishes_at) }}</p>
                        </div>
                        <p v-if="!home.projects.active.length" class="text-sm text-muted-foreground">Zurzeit gibt es keine laufenden Bauprojekte.</p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2"><Sprout class="size-5" /> Garten</CardTitle>
                        <CardDescription v-if="home.garden">Der Zustand eurer Beete.</CardDescription>
                        <CardDescription v-else>Ein Garten kann als Bauprojekt freigeschaltet werden.</CardDescription>
                    </CardHeader>
                    <CardContent v-if="home.garden?.plots.length" class="grid grid-cols-2 gap-3">
                        <div v-for="plot in home.garden.plots" :key="plot.slot" class="rounded-xl border p-3">
                            <p class="font-bold">Beet {{ plot.slot }}</p>
                            <p class="mt-1 text-sm text-muted-foreground">{{ plantName(plot.plant_key) ?? 'Noch nicht bepflanzt' }}</p>
                            <p class="text-sm">{{ gardenStage[plot.stage] }}</p>
                            <p v-if="plot.stage === 'growing' && plot.ready_at" class="mt-1 text-xs text-muted-foreground">
                                Reif etwa {{ dateTime(plot.ready_at) }}
                            </p>
                        </div>
                    </CardContent>
                    <CardContent v-else>
                        <p class="text-sm text-muted-foreground">Sobald der Garten gebaut ist, erscheinen eure Beete hier.</p>
                    </CardContent>
                </Card>

                <Card>
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
            </section>
        </div>
    </AppLayout>
</template>
