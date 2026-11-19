<script setup lang="ts">
import Emoji from '@/components/game/Emoji.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { CalendarRange, Pencil, Play, RotateCcw } from 'lucide-vue-next';
import { computed, ref } from 'vue';

type Definition = Record<string, unknown> & { key: string; name: string; icon: string };

interface Entry {
    key: string;
    definition: Definition;
    base: Definition;
    overridden: string[];
    running?: number;
}

interface CalendarYear {
    year: number;
    easter: string;
    events: { key: string; name: string; icon: string; trigger: string; default_enabled: boolean; from: string | null; to: string | null }[];
}

const props = defineProps<{
    events: Entry[];
    fun: Entry[];
    families: { id: number; name: string; members: { id: number; name: string }[] }[];
    region: string;
    subdivisions: Record<string, string>;
    calendar: CalendarYear[];
}>();

const tab = ref<'event' | 'fun'>('event');
const categoryLabel: Record<string, string> = { calendar: 'Kalender', game: 'Spiel', family: 'Familie', world: 'Welt' };
const dm = (md: string) => md.split('-').reverse().join('.') + '.';
const date = (iso: string) => new Date(`${iso}T12:00:00`).toLocaleDateString('de-DE', { day: 'numeric', month: 'short' });

function triggerText(def: Definition): string {
    const t = (def.trigger ?? {}) as Record<string, unknown>;
    switch (t.type) {
        case 'fixed':
            return t.start === t.end ? `am ${dm(String(t.start))}` : `${dm(String(t.start))} – ${dm(String(t.end))}`;
        case 'season':
            return `ab ${dm(String(t.start))}`;
        case 'easter_offset':
            return `Ostern ${Number(t.from) >= 0 ? '+' : ''}${t.from} bis ${Number(t.to) >= 0 ? '+' : ''}${t.to} Tage`;
        case 'rule':
            return 'berechnet (Advent, Erntedank …)';
        case 'school_holidays':
            return 'Schulferien';
        case 'weather':
            return 'Wetter';
        case 'game':
            return 'Ereignis im Spiel';
        case 'family_date':
            return 'Familiendatum';
        case 'random':
            return 'Zufall';
        default:
            return String(t.type ?? '–');
    }
}

// ---------- Jetzt für Familie X starten ----------
const startForm = useForm({ family_id: null as number | null, key: '', subject_user_id: null as number | null });
const startFamily = computed(() => props.families.find((f) => f.id === startForm.family_id));
const startEvent = computed(() => props.events.find((e) => e.key === startForm.key)?.definition);
const needsSubject = computed(() => Boolean(startEvent.value?.personal) || startForm.key === 'welcome_party');
function start() {
    startForm
        .transform((d) => ({ ...d, subject_user_id: needsSubject.value ? d.subject_user_id : null }))
        .post(route('admin.events.start'), { preserveScroll: true });
}

// ---------- Jahreskalender ----------
function changeRegion(e: Event) {
    router.get(route('admin.events.index'), { region: (e.target as HTMLSelectElement).value }, { preserveState: true, preserveScroll: true });
}
function bar(year: number, from: string, to: string) {
    const start = Date.UTC(year, 0, 1);
    const days = (Date.UTC(year + 1, 0, 1) - start) / 864e5;
    const f = (Date.parse(`${from}T00:00:00Z`) - start) / 864e5;
    const t = Math.min(days - 1, (Date.parse(`${to}T00:00:00Z`) - start) / 864e5);
    return { left: `${(f / days) * 100}%`, width: `${Math.max(0.8, ((t - f + 1) / days) * 100)}%` };
}
const months = ['Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'];

// ---------- Bearbeiten ----------
const EVENT_SIMPLE = ['key', 'category', 'name', 'icon', 'description', 'variants', 'duration_days', 'cooldown_days', 'priority', 'default_enabled'];
const FUN_SIMPLE = ['key', 'scope', 'action', 'name', 'icon', 'text', 'chance', 'cooldown_hours', 'minutes', 'calm', 'default_enabled', 'first_wins'];

const open = ref(false);
const editKind = ref<'event' | 'fun'>('event');
const editing = ref<Entry | null>(null);
const advanced = ref('');
const jsonError = ref<string | null>(null);
const simple = ref<Record<string, any>>({});
const form = useForm<{ definition: Record<string, any> }>({ definition: {} });
const errors = computed(() => Object.values(form.errors as Record<string, string>));

function edit(kind: 'event' | 'fun', entry: Entry) {
    editKind.value = kind;
    editing.value = entry;
    const d = entry.definition as Record<string, any>;
    simple.value =
        kind === 'event'
            ? {
                  name: d.name,
                  icon: d.icon,
                  description: d.description ?? '',
                  child: d.variants?.child ?? '',
                  teen: d.variants?.teen ?? '',
                  duration_days: d.duration_days,
                  cooldown_days: d.cooldown_days,
                  priority: d.priority,
                  default_enabled: Boolean(d.default_enabled),
              }
            : {
                  name: d.name,
                  icon: d.icon,
                  child: d.text?.child ?? '',
                  teen: d.text?.teen ?? '',
                  adult: d.text?.adult ?? '',
                  chance: d.chance,
                  cooldown_hours: d.cooldown_hours,
                  minutes: d.minutes,
                  calm: Boolean(d.calm),
                  default_enabled: Boolean(d.default_enabled ?? true),
                  first_wins: Boolean(d.first_wins),
              };
    const skip = kind === 'event' ? EVENT_SIMPLE : FUN_SIMPLE;
    advanced.value = JSON.stringify(Object.fromEntries(Object.entries(d).filter(([k]) => !skip.includes(k))), null, 2);
    jsonError.value = null;
    form.clearErrors();
    open.value = true;
}

function save() {
    if (!editing.value) return;
    let extra: Record<string, unknown>;
    try {
        extra = JSON.parse(advanced.value || '{}');
        if (typeof extra !== 'object' || extra === null || Array.isArray(extra)) throw new Error('kein Objekt');
    } catch {
        jsonError.value = 'Die weiteren Einstellungen sind kein gültiges JSON-Objekt.';
        return;
    }
    jsonError.value = null;
    const d = editing.value.definition as Record<string, any>;
    const s = simple.value;
    form.definition =
        editKind.value === 'event'
            ? {
                  ...extra,
                  category: d.category,
                  name: s.name,
                  icon: s.icon,
                  description: s.description,
                  variants: { child: s.child || null, teen: s.teen || null },
                  duration_days: Number(s.duration_days),
                  cooldown_days: Number(s.cooldown_days),
                  priority: Number(s.priority),
                  default_enabled: Boolean(s.default_enabled),
              }
            : {
                  ...extra,
                  scope: d.scope ?? 'user',
                  action: d.action ?? 'none',
                  name: s.name,
                  icon: s.icon,
                  text: { child: s.child, teen: s.teen || null, adult: s.adult || null },
                  chance: Number(s.chance),
                  cooldown_hours: Number(s.cooldown_hours),
                  minutes: Number(s.minutes),
                  calm: Boolean(s.calm),
                  default_enabled: Boolean(s.default_enabled),
                  first_wins: Boolean(s.first_wins),
              };
    form.put(route('admin.events.update', { kind: editKind.value, key: editing.value.key }), {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    });
}

function reset() {
    if (!editing.value || !confirm(`Alle Änderungen an „${editing.value.definition.name}“ verwerfen?`)) return;
    router.delete(route('admin.events.destroy', { kind: editKind.value, key: editing.value.key }), {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    });
}

const selectClass = 'h-9 rounded-md border border-input bg-background px-3 text-sm';
const chip = (active: boolean) =>
    `rounded-lg border px-3 py-1.5 text-sm font-semibold transition ${active ? 'border-primary bg-primary text-primary-foreground' : 'hover:bg-muted'}`;
</script>

<template>
    <Head title="Event-Editor" />

    <AppLayout :breadcrumbs="[{ title: 'Event-Editor', href: '/admin/events' }]">
        <div class="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
            <div>
                <h1 class="text-2xl font-extrabold tracking-tight">Event-Editor</h1>
                <p class="text-sm text-muted-foreground">
                    Inhalte aus events.json und fun_events.json. Änderungen werden in der Datenbank gespeichert, die Dateien bleiben unverändert.
                </p>
            </div>

            <!-- Jetzt starten -->
            <section class="grid gap-3 rounded-2xl border bg-card p-5">
                <h2 class="flex items-center gap-2 font-extrabold"><Play class="size-5" /> Jetzt für eine Familie starten</h2>
                <form class="flex flex-wrap items-center gap-2" @submit.prevent="start">
                    <select v-model.number="startForm.family_id" required :class="selectClass" aria-label="Familie">
                        <option :value="null" disabled>Familie wählen</option>
                        <option v-for="family in families" :key="family.id" :value="family.id">{{ family.name }}</option>
                    </select>
                    <select v-model="startForm.key" required :class="selectClass" aria-label="Event">
                        <option value="" disabled>Event wählen</option>
                        <option v-for="event in events" :key="event.key" :value="event.key">
                            {{ event.definition.icon }} {{ event.definition.name }}
                        </option>
                    </select>
                    <select v-if="needsSubject" v-model.number="startForm.subject_user_id" required :class="selectClass" aria-label="Für wen">
                        <option :value="null" disabled>Für wen?</option>
                        <option v-for="member in startFamily?.members ?? []" :key="member.id" :value="member.id">{{ member.name }}</option>
                    </select>
                    <Button type="submit" :disabled="startForm.processing || !startForm.family_id || !startForm.key">Starten</Button>
                </form>
                <p v-for="message in Object.values(startForm.errors)" :key="message" class="text-sm text-red-600">{{ message }}</p>
                <p class="text-xs text-muted-foreground">
                    Startet sofort, auch wenn das Event in der Familie abgeschaltet ist. Laufen dort schon drei Events, wartet es bis zu drei Tage.
                </p>
            </section>

            <!-- Jahreskalender -->
            <section class="grid gap-4 rounded-2xl border bg-card p-5">
                <div class="flex flex-wrap items-center gap-3">
                    <h2 class="flex flex-1 items-center gap-2 font-extrabold"><CalendarRange class="size-5" /> Jahreskalender</h2>
                    <select :value="region" :class="selectClass" aria-label="Bundesland" @change="changeRegion">
                        <option v-for="(label, code) in subdivisions" :key="code" :value="code">{{ label }}</option>
                    </select>
                </div>
                <div v-for="year in calendar" :key="year.year" class="grid gap-2">
                    <p class="text-sm font-bold">
                        {{ year.year }} <span class="font-normal text-muted-foreground">· Ostersonntag {{ date(year.easter) }}</span>
                    </p>
                    <div class="grid grid-cols-[9rem_1fr] gap-x-3 gap-y-1 text-xs sm:grid-cols-[13rem_1fr]">
                        <span />
                        <div class="grid grid-cols-12 text-[10px] text-muted-foreground">
                            <span v-for="month in months" :key="month" class="border-l pl-0.5">{{ month }}</span>
                        </div>
                        <template v-for="event in year.events" :key="`${year.year}-${event.key}`">
                            <span class="truncate" :class="event.default_enabled ? '' : 'text-muted-foreground'" :title="event.name">
                                <Emoji :glyph="event.icon" /> {{ event.name }}
                            </span>
                            <div class="relative h-5 rounded bg-muted/50">
                                <div
                                    v-if="event.from && event.to"
                                    class="absolute top-0.5 h-4 rounded bg-amber-400/80"
                                    :class="{ 'opacity-50': !event.default_enabled }"
                                    :style="bar(year.year, event.from, event.to)"
                                    :title="`${date(event.from)} – ${date(event.to)}`"
                                />
                                <span v-if="event.from && event.to" class="absolute right-1 top-0.5 text-[10px] text-muted-foreground"
                                    >{{ date(event.from) }} – {{ date(event.to) }}</span
                                >
                                <span v-else class="absolute left-1 top-0.5 text-[10px] text-muted-foreground">Schulferien noch nicht bekannt</span>
                            </div>
                        </template>
                    </div>
                </div>
                <p class="text-xs text-muted-foreground">Blasse Einträge sind standardmäßig aus. Schulferien kommen aus der OpenHolidays API.</p>
            </section>

            <!-- Liste -->
            <div class="flex flex-wrap gap-2">
                <button :class="chip(tab === 'event')" @click="tab = 'event'">Events ({{ events.length }})</button>
                <button :class="chip(tab === 'fun')" @click="tab = 'fun'">Zufallsereignisse ({{ fun.length }})</button>
            </div>

            <div class="overflow-x-auto rounded-xl border bg-card">
                <table v-if="tab === 'event'" class="w-full text-sm">
                    <thead class="bg-muted/60 text-left text-xs uppercase text-muted-foreground">
                        <tr>
                            <th class="p-3">Event</th>
                            <th class="p-3">Art</th>
                            <th class="p-3">Auslöser</th>
                            <th class="p-3">Dauer</th>
                            <th class="p-3">Standard</th>
                            <th class="p-3">Läuft</th>
                            <th class="p-3" />
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="entry in events" :key="entry.key">
                            <td class="p-3">
                                <div class="flex items-center gap-2">
                                    <span class="text-2xl"><Emoji :glyph="entry.definition.icon" /></span>
                                    <div>
                                        <p class="font-semibold">
                                            {{ entry.definition.name }}
                                            <span
                                                v-if="entry.overridden.length"
                                                class="ml-1 rounded-full bg-sky-100 px-2 py-0.5 text-xs font-bold text-sky-700 dark:bg-sky-900/40 dark:text-sky-300"
                                                :title="entry.overridden.join(', ')"
                                                >geändert</span
                                            >
                                        </p>
                                        <p class="font-mono text-xs text-muted-foreground">{{ entry.key }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="p-3">{{ categoryLabel[String(entry.definition.category)] }}</td>
                            <td class="p-3">{{ triggerText(entry.definition) }}</td>
                            <td class="p-3 tabular-nums">{{ entry.definition.duration_days }} T.</td>
                            <td class="p-3">{{ entry.definition.default_enabled ? 'an' : 'aus' }}</td>
                            <td class="p-3 tabular-nums">{{ entry.running || '–' }}</td>
                            <td class="p-3 text-right">
                                <button
                                    class="rounded-md p-1.5 hover:bg-muted"
                                    :aria-label="`${entry.definition.name} bearbeiten`"
                                    @click="edit('event', entry)"
                                >
                                    <Pencil class="size-4" />
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <table v-else class="w-full text-sm">
                    <thead class="bg-muted/60 text-left text-xs uppercase text-muted-foreground">
                        <tr>
                            <th class="p-3">Zufallsereignis</th>
                            <th class="p-3">Chance</th>
                            <th class="p-3">Abklingzeit</th>
                            <th class="p-3">Ruhig</th>
                            <th class="p-3">Standard</th>
                            <th class="p-3" />
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="entry in fun" :key="entry.key">
                            <td class="p-3">
                                <div class="flex items-center gap-2">
                                    <span class="text-2xl"><Emoji :glyph="entry.definition.icon" /></span>
                                    <div>
                                        <p class="font-semibold">
                                            {{ entry.definition.name }}
                                            <span
                                                v-if="entry.overridden.length"
                                                class="ml-1 rounded-full bg-sky-100 px-2 py-0.5 text-xs font-bold text-sky-700 dark:bg-sky-900/40 dark:text-sky-300"
                                                :title="entry.overridden.join(', ')"
                                                >geändert</span
                                            >
                                        </p>
                                        <p class="font-mono text-xs text-muted-foreground">{{ entry.key }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="p-3 tabular-nums">{{ Math.round(Number(entry.definition.chance) * 100) }} %</td>
                            <td class="p-3 tabular-nums">{{ entry.definition.cooldown_hours }} Std.</td>
                            <td class="p-3">{{ entry.definition.calm ? 'ja' : 'nein' }}</td>
                            <td class="p-3">{{ entry.definition.default_enabled === false ? 'aus' : 'an' }}</td>
                            <td class="p-3 text-right">
                                <button
                                    class="rounded-md p-1.5 hover:bg-muted"
                                    :aria-label="`${entry.definition.name} bearbeiten`"
                                    @click="edit('fun', entry)"
                                >
                                    <Pencil class="size-4" />
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <Dialog v-model:open="open">
            <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>{{ editing?.definition.name }} bearbeiten</DialogTitle>
                </DialogHeader>
                <form v-if="editing" class="grid gap-3" @submit.prevent="save">
                    <div class="grid grid-cols-[4rem_1fr] gap-3">
                        <div class="grid gap-1.5">
                            <Label for="e-icon">Symbol</Label>
                            <Input id="e-icon" v-model="simple.icon" class="text-center text-xl" required />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="e-name">Name</Label>
                            <Input id="e-name" v-model="simple.name" required maxlength="60" />
                        </div>
                    </div>

                    <template v-if="editKind === 'event'">
                        <div class="grid gap-1.5">
                            <Label for="e-desc">Beschreibung</Label>
                            <Input id="e-desc" v-model="simple.description" required maxlength="500" />
                        </div>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div class="grid gap-1.5">
                                <Label for="e-child">Text für 9–12</Label>
                                <textarea
                                    id="e-child"
                                    v-model="simple.child"
                                    rows="3"
                                    maxlength="500"
                                    class="rounded-md border bg-background p-2 text-sm"
                                />
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="e-teen">Text für 13–16</Label>
                                <textarea
                                    id="e-teen"
                                    v-model="simple.teen"
                                    rows="3"
                                    maxlength="500"
                                    class="rounded-md border bg-background p-2 text-sm"
                                />
                            </div>
                        </div>
                        <div class="grid grid-cols-3 gap-3">
                            <div class="grid gap-1.5">
                                <Label for="e-dur">Dauer (Tage)</Label>
                                <Input id="e-dur" v-model.number="simple.duration_days" type="number" min="1" max="60" />
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="e-cool">Abklingzeit (Tage)</Label>
                                <Input id="e-cool" v-model.number="simple.cooldown_days" type="number" min="0" max="365" />
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="e-prio">Priorität</Label>
                                <Input id="e-prio" v-model.number="simple.priority" type="number" min="0" max="1000" />
                            </div>
                        </div>
                    </template>

                    <template v-else>
                        <div class="grid gap-3 sm:grid-cols-3">
                            <div
                                v-for="(label, field) in { child: 'Text 9–12', teen: 'Text 13–16', adult: 'Text Erwachsene' }"
                                :key="field"
                                class="grid gap-1.5"
                            >
                                <Label :for="`f-${field}`">{{ label }}</Label>
                                <textarea
                                    :id="`f-${field}`"
                                    v-model="simple[field]"
                                    rows="3"
                                    maxlength="300"
                                    :required="field === 'child'"
                                    class="rounded-md border bg-background p-2 text-sm"
                                />
                            </div>
                        </div>
                        <div class="grid grid-cols-3 gap-3">
                            <div class="grid gap-1.5">
                                <Label for="f-chance">Chance (0–1)</Label>
                                <Input id="f-chance" v-model.number="simple.chance" type="number" min="0" max="1" step="0.01" />
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="f-cool">Abklingzeit (Std.)</Label>
                                <Input id="f-cool" v-model.number="simple.cooldown_hours" type="number" min="0" max="2000" />
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="f-min">Sichtbar (Min.)</Label>
                                <Input id="f-min" v-model.number="simple.minutes" type="number" min="1" max="2880" />
                            </div>
                        </div>
                        <div class="flex flex-wrap gap-4 text-sm">
                            <label class="flex items-center gap-2"><input v-model="simple.calm" type="checkbox" /> Ruhig (auch bei „dezent“)</label>
                            <label class="flex items-center gap-2"><input v-model="simple.first_wins" type="checkbox" /> Wer zuerst kommt</label>
                        </div>
                    </template>

                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="simple.default_enabled" type="checkbox" /> Für neue Familien eingeschaltet
                    </label>

                    <div class="grid gap-1.5">
                        <Label for="e-json">Weitere Einstellungen (JSON)</Label>
                        <textarea
                            id="e-json"
                            v-model="advanced"
                            rows="12"
                            spellcheck="false"
                            class="rounded-md border bg-background p-2 font-mono text-xs"
                        />
                        <p class="text-xs text-muted-foreground">
                            Auslöser, Ziel und Stufen, Funde, Deko, Belohnungen … Art des Auslösers, Kategorie und Aktion bleiben fest. Sterne sind
                            nicht erlaubt.
                        </p>
                    </div>

                    <div v-if="jsonError || errors.length" class="rounded-md bg-red-50 p-2 text-sm text-red-700 dark:bg-red-950/40 dark:text-red-300">
                        <p v-if="jsonError">{{ jsonError }}</p>
                        <p v-for="message in errors" :key="message">{{ message }}</p>
                    </div>

                    <DialogFooter class="gap-2">
                        <Button v-if="editing.overridden.length" type="button" variant="outline" @click="reset">
                            <RotateCcw class="size-4" /> Auf Datei zurücksetzen
                        </Button>
                        <Button type="submit" :disabled="form.processing">Speichern</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
