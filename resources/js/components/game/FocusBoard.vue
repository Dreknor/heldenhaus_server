<script setup lang="ts">
import HeroAvatar from '@/components/game/HeroAvatar.vue';
import VisualTimer from '@/components/game/VisualTimer.vue';
import { praise, useCelebrate } from '@/composables/useCelebrate';
import { dueLabel, newUuid } from '@/lib/game';
import type { AssignedTask, SharedData } from '@/types';
import { router, usePage } from '@inertiajs/vue3';
import { ArrowLeft, Check, Pause, Plus, Shuffle } from 'lucide-vue-next';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';

/**
 * Fokus-Modus „Clean-Fokus“ (Konzept §11.4): Statt vier Spalten gibt es genau eine nächste Aufgabe.
 * Man wählt selbst aus höchstens drei Vorschlägen (Autonomie), sieht nur den nächsten Schritt,
 * hat einen großen Abhak-Knopf und einen Timer ohne Ziffern. Die Tagesreise zeigt den Fortschritt als Weg.
 */
type Focus = {
    id: number;
    task_id: number | null;
    task_title: string | null;
    planned_minutes: number;
    ends_at: string;
} | null;

const props = defineProps<{
    dailies: AssignedTask[];
    todos: AssignedTask[];
    focus: Focus;
    lowEnergy: boolean;
    timerStyle: 'disc' | 'digits';
}>();
const emit = defineEmits<{ showAll: [] }>();

const page = usePage<SharedData>();
const { celebrate } = useCelebrate();
const STORE_KEY = 'heldenhaus.focus.selected';

const busy = ref(false);
const bigButton = ref<HTMLElement | null>(null);
const shuffleOffset = ref(0);
const selectedId = ref<number | null>(readStored());

function readStored(): number | null {
    try {
        const v = window.localStorage.getItem(STORE_KEY);
        return v ? Number(v) : null;
    } catch {
        return null;
    }
}
watch(selectedId, (id) => {
    try {
        if (id) window.localStorage.setItem(STORE_KEY, String(id));
        else window.localStorage.removeItem(STORE_KEY);
    } catch {
        // Ohne Speicher wird die Auswahl nur nicht gemerkt
    }
});

const actionable = (t: AssignedTask) => !t.completed && !t.pending_approval && t.on_duty !== false;

// Tagesziele: heute fällige Dailies, am Energiespar-Tag nur Kern-Aufgaben (Rest ist Bonus)
const dayGoals = computed(() => {
    const due = props.dailies.filter((d) => d.due_today && d.on_duty !== false);
    if (!props.lowEnergy) return due;
    const core = due.filter((d) => d.is_core);
    return core.length ? core : [...due].sort((a, b) => a.difficulty - b.difficulty).slice(0, 2);
});
const goalsDone = computed(() => dayGoals.value.filter((d) => d.completed).length);
const allGoalsDone = computed(() => dayGoals.value.length > 0 && goalsDone.value === dayGoals.value.length);

function rank(t: AssignedTask): number {
    let score = 0;
    if (t.type === 'daily' && t.due_today) score += 100;
    if (t.is_core) score += 40;
    if (t.type === 'todo' && t.due_date) {
        const nights = Math.round((new Date(t.due_date).getTime() - Date.now()) / 86_400_000);
        score += nights <= 0 ? 80 : nights <= 2 ? 50 : 10;
    }
    if (t.steps.some((s) => s.done)) score += 30; // Angefangenes zuerst fertig machen
    score -= t.difficulty * 5; // kurze, leichte Aufgaben bevorzugen
    return score;
}

const candidates = computed(() =>
    [...props.dailies.filter((d) => d.due_today !== false), ...props.todos].filter(actionable).sort((a, b) => rank(b) - rank(a)),
);
const suggestions = computed(() => {
    const list = candidates.value;
    if (list.length <= 3) return list;
    const start = (shuffleOffset.value * 3) % list.length;
    return [...list, ...list].slice(start, start + 3);
});

const all = computed(() => [...props.dailies, ...props.todos]);
const anyDoneToday = computed(() => all.value.some((t) => t.completed));
const selected = computed(() => all.value.find((t) => t.assignment_id === selectedId.value) ?? null);
const nextStep = computed(() => selected.value?.steps.find((s) => !s.done) ?? null);
const stepsDone = computed(() => selected.value?.steps.filter((s) => s.done).length ?? 0);

// Erledigte oder verschwundene Auswahl nach der Feier zurücksetzen
watch(
    () => selected.value?.completed,
    (done) => {
        if (done) setTimeout(() => (selectedId.value = null), 1400);
    },
);
watch(all, () => {
    if (selectedId.value && !selected.value) selectedId.value = null;
});

function choose(t: AssignedTask) {
    selectedId.value = t.assignment_id;
}

function complete() {
    const t = selected.value;
    if (!t || busy.value || t.completed) return;
    busy.value = true;
    const opts = { preserveScroll: true, onFinish: () => (busy.value = false) };
    if (nextStep.value) {
        const last = t.steps.filter((s) => !s.done).length === 1;
        const goalsAfter = goalsDone.value + (last && dayGoals.value.includes(t) ? 1 : 0);
        celebrate(last ? (goalsAfter === dayGoals.value.length ? 'big' : 'task') : 'step', bigButton.value, last ? praise() : 'Schritt geschafft!');
        router.post(route('steps.score', { assignment: t.assignment_id, step: nextStep.value.index }), { client_uuid: newUuid() }, opts);
    } else {
        const finishesDay = dayGoals.value.includes(t) && goalsDone.value + 1 === dayGoals.value.length;
        celebrate(finishesDay ? 'big' : 'task', bigButton.value, praise());
        router.post(route('assignments.score', { assignment: t.assignment_id, direction: 'up' }), { client_uuid: newUuid() }, opts);
    }
    if (props.focus) router.post(route('focus.action', { session: props.focus.id, action: 'finish' }), {}, { preserveScroll: true });
}

// Timer
const now = ref(Date.now());
let tick: ReturnType<typeof setInterval> | undefined;
onMounted(() => (tick = setInterval(() => (now.value = Date.now()), 1000)));
onUnmounted(() => tick && clearInterval(tick));

const remaining = computed(() => (props.focus ? Math.max(0, Math.ceil((Date.parse(props.focus.ends_at) - now.value) / 1000)) : 0));
const timerDone = computed(() => !!props.focus && remaining.value === 0);
watch(timerDone, (done) => done && celebrate('step', null, 'Zeit ist um!'));

function startTimer(minutes: number) {
    router.post(route('focus.start'), { minutes, task_id: selected.value?.id ?? null }, { preserveScroll: true });
}
function timerAction(action: 'finish' | 'extend' | 'abort') {
    if (!props.focus) return;
    router.post(route('focus.action', { session: props.focus.id, action }), action === 'extend' ? { minutes: 5 } : {}, { preserveScroll: true });
}

// Sonnenbahn gegen Zeitblindheit: wie viel vom Tag ist schon vorbei?
const dayFraction = computed(() => {
    const d = new Date(now.value);
    const h = d.getHours() + d.getMinutes() / 60;
    return Math.max(0, Math.min(1, (h - 6) / 15));
});
const dayHint = computed(() => {
    const d = new Date(now.value);
    const left = 21 - (d.getHours() + d.getMinutes() / 60);
    if (left <= 0) return 'Der Tag ist fast vorbei – Zeit zum Ausruhen.';
    if (left < 1) return 'Noch weniger als eine Stunde bis zum Abend.';
    return `Noch etwa ${Math.round(left)} Stunden bis zum Abend.`;
});

// Tagesreise: Wegpunkte auf einer geschwungenen Linie
const pathPoints = computed(() => {
    const n = Math.max(1, dayGoals.value.length);
    return dayGoals.value.map((g, i) => {
        const x = 8 + ((i + 0.5) / n) * 76;
        const y = 50 + Math.sin((i / Math.max(1, n - 1)) * Math.PI * 1.5) * 18;
        return { x, y, done: g.completed, title: g.title };
    });
});
const heroAt = computed(() => {
    const idx = pathPoints.value.findIndex((p) => !p.done);
    if (idx === -1) return { x: 92, y: 50 };
    if (idx === 0) return { x: 3, y: 50 };
    return pathPoints.value[idx - 1];
});
const curve = computed(() => {
    const pts = [{ x: 2, y: 50 }, ...pathPoints.value, { x: 93, y: 50 }];
    return pts.map((p, i) => (i === 0 ? `M${p.x} ${p.y}` : `S${(pts[i - 1].x + p.x) / 2} ${p.y} ${p.x} ${p.y}`)).join(' ');
});

const appearance = computed(() => page.props.auth.appearance);
</script>

<template>
    <section class="focus-board grid gap-4">
        <!-- Tagesreise -->
        <div
            class="relative overflow-hidden rounded-3xl border-2 border-emerald-200 bg-gradient-to-b from-sky-100 via-emerald-50 to-emerald-100 p-4 dark:border-emerald-900 dark:from-slate-900 dark:via-emerald-950/40 dark:to-emerald-950/60"
        >
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="text-lg font-extrabold">Deine Tagesreise</h2>
                <p class="text-sm font-semibold text-muted-foreground">
                    <template v-if="dayGoals.length">{{ goalsDone }} von {{ dayGoals.length }} Wegpunkten</template>
                    <template v-else>Heute keine festen Aufgaben – alles ist Bonus.</template>
                    <span v-if="lowEnergy" class="ml-1 rounded-full bg-amber-200 px-2 py-0.5 text-xs text-amber-900">Energiespar-Tag</span>
                </p>
            </div>
            <div v-if="dayGoals.length" class="relative mt-2 h-28 sm:h-32">
                <svg viewBox="0 0 100 100" preserveAspectRatio="none" class="absolute inset-0 size-full" aria-hidden="true">
                    <path
                        :d="curve"
                        fill="none"
                        stroke="#d6b98c"
                        stroke-width="7"
                        stroke-linecap="round"
                        vector-effect="non-scaling-stroke"
                        style="stroke-width: 18px"
                    />
                    <path
                        :d="curve"
                        fill="none"
                        stroke="#f3e2c0"
                        stroke-width="5"
                        stroke-dasharray="6 9"
                        stroke-linecap="round"
                        vector-effect="non-scaling-stroke"
                        style="stroke-width: 10px"
                    />
                </svg>
                <div class="pointer-events-none absolute inset-0">
                    <div
                        v-for="(p, i) in pathPoints"
                        :key="i"
                        class="absolute flex size-9 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full border-[3px] text-sm font-black shadow-md transition-all duration-500"
                        :class="
                            p.done
                                ? 'scale-110 border-amber-500 bg-amber-300 text-amber-900'
                                : 'border-white bg-slate-200 text-slate-500 dark:bg-slate-700 dark:text-slate-300'
                        "
                        :style="{ left: `${p.x}%`, top: `${p.y}%` }"
                        :title="p.title"
                    >
                        <span v-if="p.done">★</span><span v-else>{{ i + 1 }}</span>
                    </div>
                    <!-- Schatzkiste am Ziel -->
                    <div
                        class="absolute -translate-x-1/2 -translate-y-1/2 transition-transform duration-700"
                        :class="allGoalsDone ? 'scale-125 animate-bounce' : ''"
                        style="left: 95%; top: 50%"
                    >
                        <svg viewBox="0 0 64 56" class="h-11 w-12 drop-shadow-lg">
                            <rect x="4" y="22" width="56" height="30" rx="4" fill="#a0522d" />
                            <rect x="4" y="22" width="56" height="8" fill="#7a3e1d" />
                            <path :d="allGoalsDone ? 'M4 22 L10 2 L54 2 L60 22 Z' : 'M4 22 Q32 2 60 22 Z'" fill="#c0703a" class="transition-all" />
                            <rect x="28" y="20" width="8" height="14" rx="2" fill="#facc15" stroke="#a16207" stroke-width="1.5" />
                            <g v-if="allGoalsDone" fill="#fde047">
                                <circle cx="20" cy="18" r="4" />
                                <circle cx="32" cy="14" r="4.5" />
                                <circle cx="44" cy="18" r="4" />
                            </g>
                            <rect x="10" y="22" width="4" height="30" fill="#facc15" opacity=".7" />
                            <rect x="50" y="22" width="4" height="30" fill="#facc15" opacity=".7" />
                        </svg>
                    </div>
                    <!-- Held auf dem Weg -->
                    <div
                        class="absolute -translate-x-1/2 -translate-y-[85%] transition-all duration-700 ease-out"
                        :style="{ left: `${heroAt.x}%`, top: `${heroAt.y}%` }"
                    >
                        <HeroAvatar
                            v-if="appearance"
                            :avatar="appearance.avatar"
                            :equipment="appearance.equipment"
                            :seed="appearance.seed"
                            :class-key="appearance.class_key"
                            :size="64"
                            :backdrop="false"
                            :mood="allGoalsDone ? 'happy' : 'normal'"
                        />
                    </div>
                </div>
            </div>
            <!-- Sonnenbahn -->
            <div class="mt-3 flex items-center gap-3">
                <div class="relative h-3 flex-1 rounded-full bg-gradient-to-r from-rose-200 via-sky-300 to-indigo-400 dark:opacity-80">
                    <span
                        class="absolute top-1/2 size-6 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-white bg-yellow-300 shadow"
                        :style="{ left: `${dayFraction * 100}%` }"
                        aria-hidden="true"
                    />
                </div>
                <p class="shrink-0 text-xs font-semibold text-muted-foreground">{{ dayHint }}</p>
            </div>
        </div>

        <!-- Eine Aufgabe -->
        <Transition
            mode="out-in"
            enter-from-class="opacity-0 translate-y-2"
            leave-to-class="opacity-0 -translate-y-2"
            enter-active-class="transition duration-200"
            leave-active-class="transition duration-150"
        >
            <div v-if="selected" key="one" class="rounded-3xl border-2 border-violet-200 bg-card p-5 shadow-lg dark:border-violet-900 sm:p-7">
                <button
                    class="mb-3 flex items-center gap-1 text-sm font-semibold text-muted-foreground hover:text-foreground"
                    @click="selectedId = null"
                >
                    <ArrowLeft class="size-4" /> Andere Aufgabe wählen
                </button>
                <div class="grid items-center gap-6 md:grid-cols-[1fr_auto]">
                    <div class="min-w-0">
                        <p class="text-sm font-bold uppercase tracking-wider text-violet-600 dark:text-violet-300">Nur das hier</p>
                        <h3
                            class="mt-1 text-2xl font-black leading-tight sm:text-3xl"
                            :class="selected.completed ? 'text-emerald-600 line-through' : ''"
                        >
                            {{ nextStep ? nextStep.title : selected.title }}
                        </h3>
                        <p v-if="nextStep" class="mt-1 text-muted-foreground">gehört zu „{{ selected.title }}“</p>
                        <div
                            v-if="selected.steps.length"
                            class="mt-3 flex gap-1.5"
                            :aria-label="`${stepsDone} von ${selected.steps.length} Schritten`"
                        >
                            <span
                                v-for="s in selected.steps"
                                :key="s.index"
                                class="h-2.5 flex-1 rounded-full transition-colors duration-500"
                                :class="s.done ? 'bg-emerald-500' : s === nextStep ? 'bg-violet-300 dark:bg-violet-700' : 'bg-muted'"
                            />
                        </div>
                        <p
                            v-if="selected.type === 'todo' && selected.due_date"
                            class="mt-3 inline-block rounded-full px-2.5 py-1 text-xs font-bold"
                            :class="dueLabel(selected.due_date).tone"
                        >
                            {{ dueLabel(selected.due_date).text }}
                        </p>
                    </div>
                    <button
                        ref="bigButton"
                        class="group mx-auto flex size-36 flex-col items-center justify-center gap-1 rounded-full bg-gradient-to-b from-emerald-400 to-emerald-600 font-black text-white shadow-[0_8px_0_#047857,0_12px_24px_rgba(0,0,0,.2)] transition active:translate-y-1.5 active:shadow-[0_2px_0_#047857] disabled:opacity-60 sm:size-40"
                        :disabled="busy || selected.completed"
                        @click="complete"
                    >
                        <Check class="size-14 stroke-[3.5] transition group-hover:scale-110" />
                        <span class="text-base">{{ selected.completed ? 'Geschafft!' : nextStep ? 'Schritt fertig' : 'Erledigt' }}</span>
                    </button>
                </div>

                <!-- Timer -->
                <div class="mt-6 border-t pt-5">
                    <div v-if="focus" class="flex flex-wrap items-center gap-5">
                        <VisualTimer :remaining="remaining" :total="focus.planned_minutes * 60" :size="120" :digits="timerStyle === 'digits'" />
                        <div class="grid gap-2">
                            <p class="font-bold">{{ timerDone ? 'Die Zeit ist um. Wie lief es?' : 'Die Scheibe wird kleiner – du schaffst das.' }}</p>
                            <div class="flex flex-wrap gap-2">
                                <button
                                    class="flex items-center gap-1 rounded-xl border-2 px-3 py-2 text-sm font-bold hover:bg-muted"
                                    @click="timerAction('extend')"
                                >
                                    <Plus class="size-4" /> 5 Minuten
                                </button>
                                <button
                                    class="flex items-center gap-1 rounded-xl border-2 px-3 py-2 text-sm font-bold text-muted-foreground hover:bg-muted"
                                    @click="timerAction('abort')"
                                >
                                    <Pause class="size-4" /> Pause machen
                                </button>
                            </div>
                        </div>
                    </div>
                    <div v-else class="flex flex-wrap items-center gap-2">
                        <span class="mr-1 text-sm font-semibold text-muted-foreground">Timer dazu?</span>
                        <button
                            v-for="m in [5, 10, 15, 25]"
                            :key="m"
                            class="rounded-xl border-2 border-violet-200 px-4 py-2 font-bold text-violet-700 hover:bg-violet-50 dark:border-violet-800 dark:text-violet-300 dark:hover:bg-violet-950"
                            @click="startTimer(m)"
                        >
                            {{ m }} Min.
                        </button>
                    </div>
                </div>
            </div>

            <div
                v-else-if="suggestions.length"
                key="pick"
                class="rounded-3xl border-2 border-dashed border-violet-300 bg-violet-50/50 p-5 dark:border-violet-800 dark:bg-violet-950/20"
            >
                <div class="mb-4 flex items-center justify-between gap-2">
                    <h3 class="text-xl font-black">Was machst du als Nächstes?</h3>
                    <button
                        v-if="candidates.length > 3"
                        class="flex items-center gap-1 rounded-lg px-2 py-1 text-sm font-semibold text-muted-foreground hover:bg-muted"
                        @click="shuffleOffset++"
                    >
                        <Shuffle class="size-4" /> Andere zeigen
                    </button>
                </div>
                <div class="grid gap-3 sm:grid-cols-3">
                    <button
                        v-for="(t, i) in suggestions"
                        :key="t.assignment_id"
                        class="group flex min-h-28 flex-col items-start gap-2 rounded-2xl border-2 bg-card p-4 text-left shadow-sm transition hover:-translate-y-0.5 hover:border-violet-400 hover:shadow-md focus-visible:border-violet-500"
                        @click="choose(t)"
                    >
                        <span
                            class="flex size-8 items-center justify-center rounded-full bg-violet-100 text-sm font-black text-violet-700 dark:bg-violet-900 dark:text-violet-200"
                            >{{ i + 1 }}</span
                        >
                        <span class="text-lg font-extrabold leading-snug">{{ t.title }}</span>
                        <span class="mt-auto flex flex-wrap gap-1 text-[11px] font-bold">
                            <span v-if="t.is_core" class="rounded-full bg-amber-100 px-2 py-0.5 text-amber-800">Kern-Aufgabe</span>
                            <span v-if="t.type === 'daily'" class="rounded-full bg-emerald-100 px-2 py-0.5 text-emerald-800">heute</span>
                            <span v-if="t.type === 'todo' && t.due_date" class="rounded-full px-2 py-0.5" :class="dueLabel(t.due_date).tone">{{
                                dueLabel(t.due_date).text
                            }}</span>
                            <span v-if="t.steps.length" class="rounded-full bg-sky-100 px-2 py-0.5 text-sky-800"
                                >{{ t.steps.length }} kleine Schritte</span
                            >
                        </span>
                    </button>
                </div>
            </div>

            <div
                v-else
                key="done"
                class="rounded-3xl border-2 border-amber-300 bg-gradient-to-br from-amber-50 to-rose-50 p-8 text-center dark:border-amber-800 dark:from-amber-950/30 dark:to-rose-950/30"
            >
                <template v-if="anyDoneToday">
                    <img src="/images/items/confetti_cannon.webp" alt="" class="mx-auto size-20 drop-shadow-md" />
                    <h3 class="mt-2 text-2xl font-black">Alles geschafft für heute!</h3>
                    <p class="mt-1 text-muted-foreground">Dein Held ist stolz auf dich. Jetzt darfst du dich ausruhen.</p>
                </template>
                <template v-else>
                    <img src="/images/items/parchment.webp" alt="" class="mx-auto size-20 drop-shadow-md" />
                    <h3 class="mt-2 text-2xl font-black">Noch keine Aufgaben da</h3>
                    <p class="mt-1 text-muted-foreground">Lege eine erste kleine Aufgabe an – dann geht das Abenteuer los.</p>
                    <button class="mt-4 rounded-xl bg-violet-600 px-5 py-2.5 font-bold text-white hover:bg-violet-700" @click="emit('showAll')">
                        Aufgabe anlegen
                    </button>
                </template>
            </div>
        </Transition>
    </section>
</template>
