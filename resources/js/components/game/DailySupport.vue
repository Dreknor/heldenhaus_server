<script setup lang="ts">
import { Input } from '@/components/ui/input';
import type { AssignedTask } from '@/types';
import { router, useForm } from '@inertiajs/vue3';
import { AlarmClock, Check, Inbox, Pause, Play, Plus, Trash2, Zap } from 'lucide-vue-next';
import { computed, onMounted, onUnmounted, reactive, ref } from 'vue';

type Support = {
    low_energy_today: boolean;
    low_energy_left: number;
    low_energy_per_week: number;
    focus_mode_default: boolean;
    timer_style: 'disc' | 'digits';
};

type FocusSession = {
    id: number;
    task_id: number | null;
    task_title: string | null;
    planned_minutes: number;
    started_at: string;
    ends_at: string;
    remaining_seconds: number;
    shared: boolean;
    partners: string[];
} | null;

type InboxTask = AssignedTask & { status: string | null };
type SortDraft = { type: 'todo' | 'daily' | 'habit'; category: string; difficulty: number; due_date: string };

const props = defineProps<{
    inbox: InboxTask[];
    support: Support;
    focus: FocusSession;
    focusMembers: { id: number; name: string }[];
    focusTasks: AssignedTask[];
    categories: Record<string, string>;
}>();

const capture = useForm({ title: '' });
const drafts = reactive<Record<number, SortDraft>>({});
const sortErrors = ref<Record<number, string>>({});
const focusMinutes = ref(props.support.focus_mode_default ? 25 : 15);
const focusTaskId = ref<number | null>(null);
const inviteeId = ref<number | null>(props.focusMembers[0]?.id ?? null);
const tick = ref(Date.now());
let timer: ReturnType<typeof setInterval> | undefined;

const remainingSeconds = computed(() => {
    if (!props.focus) return 0;
    return Math.max(0, Math.ceil((Date.parse(props.focus.ends_at) - tick.value) / 1000));
});
const formattedTime = computed(() => {
    const minutes = Math.floor(remainingSeconds.value / 60)
        .toString()
        .padStart(2, '0');
    const seconds = (remainingSeconds.value % 60).toString().padStart(2, '0');
    return `${minutes}:${seconds}`;
});
const timerProgress = computed(() => {
    if (!props.focus) return 0;
    return Math.min(100, (remainingSeconds.value / (props.focus.planned_minutes * 60)) * 100);
});
const availableFocusTasks = computed(() => props.focusTasks.filter((task) => !task.completed));

function draftFor(task: InboxTask): SortDraft {
    return (drafts[task.id] ??= {
        type: 'todo',
        category: Object.keys(props.categories)[0] ?? '',
        difficulty: task.difficulty || 1,
        due_date: '',
    });
}

function captureTask() {
    const title = capture.title.trim();
    if (!title || capture.processing) return;
    capture.title = title;
    capture.post(route('inbox.store'), { preserveScroll: true, onSuccess: () => capture.reset() });
}

function sort(task: InboxTask) {
    const draft = draftFor(task);
    sortErrors.value[task.id] = '';
    router.post(
        route('inbox.sort', { task: task.id }),
        { ...draft },
        {
            preserveScroll: true,
            onError: (errors) => (sortErrors.value[task.id] = Object.values(errors).join(' ')),
        },
    );
}

function removeInboxTask(task: InboxTask) {
    router.post(route('inbox.sort', { task: task.id }), { action: 'delete' }, { preserveScroll: true });
}

function changeLowEnergy() {
    const options = { preserveScroll: true };
    if (props.support.low_energy_today) {
        router.delete(route('low-energy.cancel'), options);
    } else {
        router.post(route('low-energy.start'), {}, options);
    }
}

function startFocus() {
    router.post(route('focus.start'), { minutes: focusMinutes.value, task_id: focusTaskId.value }, { preserveScroll: true });
}

function focusAction(action: 'finish' | 'abort' | 'extend' | 'invite') {
    if (!props.focus) return;
    const payload = action === 'extend' ? { minutes: 5 } : action === 'invite' && inviteeId.value ? { member_id: inviteeId.value } : {};
    router.post(route('focus.action', { session: props.focus.id, action }), payload, { preserveScroll: true });
}

onMounted(() => {
    timer = setInterval(() => (tick.value = Date.now()), 1000);
});
onUnmounted(() => {
    if (timer) clearInterval(timer);
});
</script>

<template>
    <section class="grid gap-4 xl:grid-cols-2">
        <article class="rounded-2xl border bg-card p-4 shadow-sm">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2 class="flex items-center gap-2 text-lg font-extrabold"><Zap class="size-5 text-amber-500" /> Energiespar-Tag</h2>
                    <p class="mt-1 text-sm text-muted-foreground">Heute zählen nur die Kern-Aufgaben. Alles andere ist Bonus.</p>
                </div>
                <button
                    class="shrink-0 rounded-lg px-3 py-2 text-sm font-bold transition"
                    :class="
                        support.low_energy_today
                            ? 'border border-amber-300 bg-amber-50 text-amber-800 hover:bg-amber-100 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200'
                            : 'bg-primary text-primary-foreground hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-50'
                    "
                    :disabled="!support.low_energy_today && support.low_energy_left <= 0"
                    @click="changeLowEnergy"
                >
                    {{ support.low_energy_today ? 'Für heute ausschalten' : 'Heute aktivieren' }}
                </button>
            </div>
            <p class="mt-3 text-xs text-muted-foreground">
                {{ support.low_energy_left }} von {{ support.low_energy_per_week }} Energiespar-Tagen diese Woche übrig.
            </p>
            <p
                v-if="support.low_energy_today"
                class="mt-2 rounded-lg bg-amber-50 p-2 text-sm text-amber-900 dark:bg-amber-950/30 dark:text-amber-200"
            >
                Energiespar-Tag ist aktiv. Verpasste übrige Dailies kosten heute keine Lebenspunkte.
            </p>
        </article>

        <article class="rounded-2xl border bg-card p-4 shadow-sm">
            <div class="mb-3 flex items-center gap-2">
                <Inbox class="size-5 text-sky-600" />
                <div>
                    <h2 class="text-lg font-extrabold">Kopf-Ablage</h2>
                    <p class="text-sm text-muted-foreground">Erst festhalten, später einsortieren.</p>
                </div>
            </div>
            <form class="flex gap-2" @submit.prevent="captureTask">
                <Input v-model="capture.title" maxlength="120" placeholder="Was möchtest du festhalten?" aria-label="Eintrag für die Kopf-Ablage" />
                <button
                    class="flex shrink-0 items-center gap-1 rounded-lg bg-primary px-3 py-2 text-sm font-bold text-primary-foreground disabled:opacity-50"
                    :disabled="capture.processing || !capture.title.trim()"
                >
                    <Plus class="size-4" /> Merken
                </button>
            </form>
            <p v-if="capture.errors.title" class="mt-1 text-xs text-destructive">{{ capture.errors.title }}</p>

            <div v-if="inbox.length" class="mt-3 grid gap-2">
                <div v-for="task in inbox" :key="task.id" class="rounded-xl border bg-background p-3">
                    <div class="mb-2 flex items-start justify-between gap-2">
                        <p class="font-semibold">{{ task.title }}</p>
                        <button
                            class="rounded p-1 text-muted-foreground hover:bg-muted hover:text-destructive"
                            :aria-label="`${task.title} löschen`"
                            @click="removeInboxTask(task)"
                        >
                            <Trash2 class="size-4" />
                        </button>
                    </div>
                    <div class="grid gap-2 sm:grid-cols-2">
                        <label class="grid gap-1 text-xs font-semibold">
                            Einsortieren als
                            <select v-model="draftFor(task).type" class="h-9 rounded-md border border-input bg-background px-2 text-sm">
                                <option value="todo">To-do</option>
                                <option value="daily">Tägliche Aufgabe</option>
                                <option value="habit">Gewohnheit</option>
                            </select>
                        </label>
                        <label class="grid gap-1 text-xs font-semibold">
                            Kategorie
                            <select v-model="draftFor(task).category" class="h-9 rounded-md border border-input bg-background px-2 text-sm">
                                <option v-for="(label, key) in categories" :key="key" :value="key">{{ label }}</option>
                            </select>
                        </label>
                        <label class="grid gap-1 text-xs font-semibold">
                            Schwierigkeit
                            <select v-model.number="draftFor(task).difficulty" class="h-9 rounded-md border border-input bg-background px-2 text-sm">
                                <option :value="0.1">Trivial</option>
                                <option :value="1">Leicht</option>
                                <option :value="1.5">Mittel</option>
                                <option :value="2">Schwer</option>
                            </select>
                        </label>
                        <label v-if="draftFor(task).type === 'todo'" class="grid gap-1 text-xs font-semibold">
                            Fällig bis
                            <Input v-model="draftFor(task).due_date" type="date" class="h-9" />
                        </label>
                    </div>
                    <p v-if="sortErrors[task.id]" class="mt-1 text-xs text-destructive">{{ sortErrors[task.id] }}</p>
                    <button
                        class="mt-2 flex items-center gap-1 rounded-lg bg-sky-600 px-3 py-1.5 text-sm font-bold text-white hover:bg-sky-700"
                        @click="sort(task)"
                    >
                        <Check class="size-4" /> Einsortieren
                    </button>
                </div>
            </div>
            <p v-else class="mt-3 rounded-lg border border-dashed p-3 text-center text-sm text-muted-foreground">Noch nichts in der Kopf-Ablage.</p>
        </article>

        <article class="rounded-2xl border bg-card p-4 shadow-sm xl:col-span-2">
            <div class="mb-3 flex items-center gap-2">
                <AlarmClock class="size-5 text-violet-600" />
                <div>
                    <h2 class="text-lg font-extrabold">Fokus-Zeit</h2>
                    <p class="text-sm text-muted-foreground">Ein ruhiger Timer für eine Aufgabe – allein oder gemeinsam.</p>
                </div>
            </div>

            <template v-if="focus">
                <div class="grid gap-4 sm:grid-cols-[1fr_auto] sm:items-center">
                    <div>
                        <p class="font-semibold">{{ focus.task_title ? `Fokus: ${focus.task_title}` : 'Fokus läuft' }}</p>
                        <div
                            v-if="remainingSeconds && support.timer_style === 'disc'"
                            class="mt-2 flex size-28 items-center justify-center rounded-full p-2 text-violet-600"
                            :style="{ background: `conic-gradient(currentColor ${timerProgress}%, rgb(226 232 240) 0)` }"
                            role="timer"
                            :aria-label="`${formattedTime} verbleibend`"
                            aria-live="polite"
                        >
                            <span
                                class="flex size-full items-center justify-center rounded-full bg-card font-mono text-2xl font-extrabold tabular-nums text-foreground"
                            >
                                {{ formattedTime }}
                            </span>
                        </div>
                        <p v-else-if="remainingSeconds" class="mt-1 font-mono text-4xl font-extrabold tabular-nums" role="timer" aria-live="polite">
                            {{ formattedTime }}
                        </p>
                        <p v-else class="mt-1 text-lg font-bold text-emerald-600">Zeit ist um – gut gemacht!</p>
                        <p v-if="focus.shared" class="mt-1 text-sm text-muted-foreground">
                            Gemeinsam mit {{ focus.partners.join(', ') || 'Familienmitglied' }}.
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button
                            class="flex items-center gap-1 rounded-lg bg-emerald-600 px-3 py-2 text-sm font-bold text-white hover:bg-emerald-700"
                            @click="focusAction('finish')"
                        >
                            <Check class="size-4" /> Geschafft
                        </button>
                        <button
                            class="flex items-center gap-1 rounded-lg border px-3 py-2 text-sm font-bold hover:bg-muted"
                            @click="focusAction('extend')"
                        >
                            +5 Min.
                        </button>
                        <button
                            class="flex items-center gap-1 rounded-lg border px-3 py-2 text-sm font-bold text-muted-foreground hover:bg-muted"
                            @click="focusAction('abort')"
                        >
                            <Pause class="size-4" /> Abbrechen
                        </button>
                    </div>
                </div>
                <div v-if="focusMembers.length" class="mt-3 flex flex-wrap items-center gap-2 border-t pt-3">
                    <label class="text-sm font-semibold" for="focus-invite">Gemeinsam fokussieren</label>
                    <select id="focus-invite" v-model.number="inviteeId" class="h-9 rounded-md border border-input bg-background px-2 text-sm">
                        <option v-for="member in focusMembers" :key="member.id" :value="member.id">{{ member.name }}</option>
                    </select>
                    <button
                        class="rounded-lg border px-3 py-2 text-sm font-bold hover:bg-muted"
                        :disabled="!inviteeId"
                        @click="focusAction('invite')"
                    >
                        Einladen
                    </button>
                </div>
            </template>

            <form v-else class="grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end" @submit.prevent="startFocus">
                <label class="grid gap-1.5 text-sm font-semibold">
                    Dauer in Minuten
                    <Input v-model.number="focusMinutes" type="number" min="1" max="120" required />
                </label>
                <label class="grid gap-1.5 text-sm font-semibold">
                    Aufgabe (optional)
                    <select v-model.number="focusTaskId" class="h-9 rounded-md border border-input bg-background px-3 text-sm">
                        <option :value="null">Keine bestimmte Aufgabe</option>
                        <option v-for="task in availableFocusTasks" :key="task.assignment_id" :value="task.id">{{ task.title }}</option>
                    </select>
                </label>
                <button
                    class="flex items-center justify-center gap-2 rounded-lg bg-violet-600 px-4 py-2 text-sm font-bold text-white hover:bg-violet-700"
                >
                    <Play class="size-4" /> Fokus starten
                </button>
                <p v-if="focusMembers.length" class="text-xs text-muted-foreground sm:col-span-3">
                    Nach dem Start kannst du ein Familienmitglied einladen.
                </p>
            </form>
        </article>
    </section>
</template>
