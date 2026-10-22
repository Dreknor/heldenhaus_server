<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { CalendarDays, MapPin, Plus, Save, Sparkles, Trash2 } from 'lucide-vue-next';

interface EventSetting {
    key: string;
    name: string;
    icon: string;
    category: string;
    description: string;
    enabled: boolean;
    default_enabled: boolean;
}

interface Holiday {
    name: string;
    starts_on: string;
    ends_on: string;
}

const props = defineProps<{
    country: string;
    subdivision: string | null;
    fun_events: boolean;
    weather_source: 'none' | 'ha' | 'open_meteo';
    weather_lat: number | null;
    weather_lon: number | null;
    events: EventSetting[];
    fun: { key: string; name: string; icon: string; enabled: boolean; default_enabled: boolean }[];
    calendar: { key: string; name: string; icon: string; from: string; to: string }[];
    school_holidays: Holiday[];
}>();

const form = useForm({
    country: props.country,
    subdivision: props.subdivision ?? '',
    fun_events: props.fun_events,
    weather_source: props.weather_source,
    weather_lat: props.weather_lat,
    weather_lon: props.weather_lon,
    events: Object.fromEntries(props.events.map((event) => [event.key, event.enabled])) as Record<string, boolean>,
    fun: Object.fromEntries(props.fun.map((event) => [event.key, event.enabled])) as Record<string, boolean>,
    school_holidays: props.school_holidays.map((holiday) => ({ ...holiday })),
});

function toggleRecord(section: 'events' | 'fun', key: string, e: Event) {
    form[section][key] = (e.target as HTMLInputElement).checked;
}

function addHoliday() {
    form.school_holidays.push({ name: '', starts_on: '', ends_on: '' });
}

function removeHoliday(index: number) {
    form.school_holidays.splice(index, 1);
}

function save() {
    form.subdivision = form.subdivision.trim().toUpperCase() || '';
    form.put(route('web.events.settings.update'), { preserveScroll: true });
}

const date = (iso: string) => new Date(`${iso}T12:00:00`).toLocaleDateString('de-DE', { day: 'numeric', month: 'short', year: 'numeric' });
</script>

<template>
    <Head title="Ereigniseinstellungen" />

    <AppLayout
        :breadcrumbs="[
            { title: 'Ereignisse', href: '/events' },
            { title: 'Einstellungen', href: '/family/event-settings' },
        ]"
    >
        <form class="flex min-w-0 flex-1 flex-col gap-7 p-4 md:p-6" @submit.prevent="save">
            <header>
                <h1 class="text-2xl font-extrabold tracking-tight">Ereigniseinstellungen</h1>
                <p class="text-sm text-muted-foreground">Lege fest, welche Familienereignisse und Überraschungen aktiviert sind.</p>
            </header>
            <p
                v-if="Object.keys(form.errors).length"
                role="alert"
                class="rounded-xl border border-destructive/40 bg-destructive/5 p-3 text-sm text-destructive"
            >
                {{ Object.values(form.errors)[0] }}
            </p>

            <section class="grid gap-4 rounded-2xl border bg-card p-4">
                <h2 class="flex items-center gap-2 text-lg font-extrabold"><MapPin class="size-5" /> Region und Wetter</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="grid gap-1 text-sm font-semibold">
                        Land (ISO-Code)
                        <Input v-model="form.country" maxlength="2" autocomplete="country" class="uppercase" />
                        <span class="text-xs font-normal text-muted-foreground">Zum Beispiel DE, AT, CH oder US.</span>
                    </label>
                    <label class="grid gap-1 text-sm font-semibold">
                        Bundesland / Region (optional)
                        <Input v-model="form.subdivision" maxlength="8" placeholder="z. B. DE-BY" />
                    </label>
                </div>
                <div class="grid gap-4 sm:grid-cols-3">
                    <label class="grid gap-1 text-sm font-semibold">
                        Wetterquelle
                        <select v-model="form.weather_source" class="h-10 rounded-md border bg-background px-3 text-sm">
                            <option value="none">Keine Wetterereignisse</option>
                            <option value="ha">Home Assistant</option>
                            <option value="open_meteo">Open-Meteo</option>
                        </select>
                    </label>
                    <label class="grid gap-1 text-sm font-semibold"
                        >Breitengrad<Input v-model.number="form.weather_lat" type="number" min="-90" max="90" step="0.1"
                    /></label>
                    <label class="grid gap-1 text-sm font-semibold"
                        >Längengrad<Input v-model.number="form.weather_lon" type="number" min="-180" max="180" step="0.1"
                    /></label>
                </div>
            </section>

            <section class="grid gap-3">
                <div>
                    <h2 class="text-lg font-extrabold">Familienereignisse</h2>
                    <p class="text-sm text-muted-foreground">Ausgeschaltete Ereignisse werden nicht mehr eingeplant.</p>
                </div>
                <div class="divide-y rounded-2xl border bg-card">
                    <label v-for="eventItem in events" :key="eventItem.key" class="flex cursor-pointer items-start gap-3 p-3 hover:bg-muted/40">
                        <input
                            type="checkbox"
                            class="mt-1 size-4 accent-amber-500"
                            :checked="form.events[eventItem.key]"
                            @change="toggleRecord('events', eventItem.key, $event)"
                        />
                        <span class="text-xl">{{ eventItem.icon }}</span>
                        <span class="min-w-0 flex-1">
                            <span class="block font-bold">{{ eventItem.name }}</span>
                            <span class="block text-sm text-muted-foreground">{{ eventItem.description }}</span>
                        </span>
                        <span class="text-xs text-muted-foreground">{{ eventItem.category }}</span>
                    </label>
                </div>
            </section>

            <section class="grid gap-3 rounded-2xl border bg-card p-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="flex items-center gap-2 text-lg font-extrabold"><Sparkles class="size-5 text-violet-500" /> Zufallsereignisse</h2>
                        <p class="text-sm text-muted-foreground">Aktiviere kleine Begegnungen und Grüße im Familienalltag.</p>
                    </div>
                    <label class="flex items-center gap-2 text-sm font-semibold">
                        <input v-model="form.fun_events" type="checkbox" class="size-4 accent-violet-500" />
                        Zufallsereignisse erlauben
                    </label>
                </div>
                <div class="grid gap-2 sm:grid-cols-2">
                    <label v-for="funItem in fun" :key="funItem.key" class="flex cursor-pointer items-center gap-2 rounded-lg border p-3 text-sm">
                        <input
                            type="checkbox"
                            class="size-4 accent-violet-500"
                            :checked="form.fun[funItem.key]"
                            :disabled="!form.fun_events"
                            @change="toggleRecord('fun', funItem.key, $event)"
                        />
                        <span>{{ funItem.icon }}</span
                        ><span class="font-semibold">{{ funItem.name }}</span>
                    </label>
                </div>
            </section>

            <section class="grid gap-3 rounded-2xl border bg-card p-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h2 class="flex items-center gap-2 text-lg font-extrabold"><CalendarDays class="size-5 text-sky-600" /> Schulferien</h2>
                        <p class="text-sm text-muted-foreground">Eigene Ferientermine gelten zusätzlich zum Kalender der ausgewählten Region.</p>
                    </div>
                    <Button type="button" variant="outline" size="sm" @click="addHoliday"><Plus class="mr-1 size-4" /> Zeitraum hinzufügen</Button>
                </div>
                <div v-for="(holiday, index) in form.school_holidays" :key="index" class="grid items-end gap-2 sm:grid-cols-[1fr_1fr_1fr_auto]">
                    <label class="grid gap-1 text-xs font-semibold"
                        >Name<Input v-model="holiday.name" maxlength="60" placeholder="z. B. Winterferien"
                    /></label>
                    <label class="grid gap-1 text-xs font-semibold">Beginn<Input v-model="holiday.starts_on" type="date" /></label>
                    <label class="grid gap-1 text-xs font-semibold">Ende<Input v-model="holiday.ends_on" type="date" /></label>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        :aria-label="`${holiday.name || 'Zeitraum'} entfernen`"
                        @click="removeHoliday(index)"
                        ><Trash2 class="size-4"
                    /></Button>
                </div>
                <p v-if="!form.school_holidays.length" class="text-sm text-muted-foreground">Keine eigenen Ferientermine eingetragen.</p>
            </section>

            <section class="grid gap-3">
                <h2 class="text-lg font-extrabold">Vorschau des Ereigniskalenders</h2>
                <div v-if="calendar.length" class="divide-y rounded-2xl border bg-card">
                    <div v-for="entry in calendar" :key="`${entry.key}-${entry.from}`" class="flex items-center gap-3 p-3">
                        <span class="text-xl">{{ entry.icon }}</span
                        ><span class="flex-1 font-semibold">{{ entry.name }}</span>
                        <span class="text-sm text-muted-foreground">{{ date(entry.from) }} – {{ date(entry.to) }}</span>
                    </div>
                </div>
                <p v-else class="rounded-xl border border-dashed p-4 text-sm text-muted-foreground">
                    Für die Region sind derzeit keine Kalendertermine verfügbar.
                </p>
            </section>

            <div class="sticky bottom-0 flex justify-end border-t bg-background/90 py-3 backdrop-blur">
                <Button type="submit" :disabled="form.processing"><Save class="mr-2 size-4" /> Einstellungen speichern</Button>
            </div>
        </form>
    </AppLayout>
</template>
