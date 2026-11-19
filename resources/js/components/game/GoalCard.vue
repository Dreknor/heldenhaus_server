<script setup lang="ts">
import Emoji from '@/components/game/Emoji.vue';
import { router } from '@inertiajs/vue3';
import { Check, Flag, HandHeart, Lock, Minus, Pencil, Plus } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { type Goal, progressLabel } from './goals';

/** Ziel-Karte (§14.1): Warum, Fortschritt, Meilensteine, Zähler, Unterstützer. Verpasstes Enddatum fragt freundlich nach. */
const props = defineProps<{ goal: Goal; isParent?: boolean }>();
const emit = defineEmits<{ edit: [goal: Goal] }>();

const busy = ref(false);
const extendTo = ref('');
const post = (name: string, params: Record<string, number>, data: Record<string, unknown> = {}) => {
    busy.value = true;
    router.post(route(name, params), data as never, { preserveScroll: true, onFinish: () => (busy.value = false) });
};

const nextMilestone = computed(() => props.goal.milestones.find((m) => !m.done));
const barTone = computed(() =>
    props.goal.status === 'reached' ? 'bg-amber-400' : props.goal.progress.percent >= 100 ? 'bg-emerald-500' : 'bg-sky-500',
);

function extend() {
    if (!extendTo.value) return;
    busy.value = true;
    router.put(
        route('goals.update', { goal: props.goal.id }),
        { ends_on: extendTo.value },
        { preserveScroll: true, onFinish: () => (busy.value = false) },
    );
}
</script>

<template>
    <article class="hh-panel hh-parchment flex flex-col gap-3 p-4" :class="goal.status === 'reached' && 'ring-2 ring-amber-300'">
        <header class="flex items-start gap-3">
            <span class="text-3xl leading-none"><Emoji :glyph="goal.icon" /></span>
            <div class="min-w-0 flex-1">
                <h3 class="flex flex-wrap items-center gap-2 font-extrabold">
                    {{ goal.title }}
                    <Lock v-if="goal.is_private" class="size-3.5 text-muted-foreground" aria-label="privat" />
                    <span class="rounded-full bg-muted px-2 py-0.5 text-[11px] font-semibold text-muted-foreground">{{ goal.type_label }}</span>
                </h3>
                <p class="text-sm italic text-muted-foreground">„{{ goal.why }}“</p>
            </div>
            <button
                v-if="goal.can_edit && (goal.status === 'active' || goal.status === 'expired')"
                class="rounded-md p-1.5 hover:bg-muted"
                aria-label="Ziel bearbeiten"
                @click="emit('edit', goal)"
            >
                <Pencil class="size-4" />
            </button>
        </header>

        <div>
            <div class="mb-1 flex justify-between text-xs font-semibold">
                <span>{{ progressLabel(goal) }}</span>
                <span v-if="goal.type === 'weekly' && goal.weeks_reached" class="text-amber-700 dark:text-amber-300"
                    >{{ goal.weeks_reached }}× geschafft</span
                >
                <span v-else-if="goal.ends_on" class="text-muted-foreground">bis {{ new Date(goal.ends_on).toLocaleDateString('de-DE') }}</span>
            </div>
            <div
                class="h-3 overflow-hidden rounded-full bg-muted"
                role="progressbar"
                :aria-valuenow="goal.progress.percent"
                aria-valuemin="0"
                aria-valuemax="100"
            >
                <div class="h-full rounded-full transition-all" :class="barTone" :style="{ width: `${Math.max(4, goal.progress.percent)}%` }" />
            </div>
            <p v-if="goal.type === 'weekly' && goal.progress.skipped" class="mt-1 text-xs text-muted-foreground">
                {{ goal.progress.skipped }} Tag(e) ausgelassen – das Ziel ist diese Woche etwas kleiner.
            </p>
        </div>

        <!-- Meilensteine -->
        <ol v-if="goal.type === 'milestone'" class="grid gap-1.5">
            <li v-for="m in goal.milestones" :key="m.id" class="flex items-center gap-2 text-sm">
                <span
                    class="flex size-6 shrink-0 items-center justify-center rounded-full border-2"
                    :class="
                        m.done
                            ? 'border-emerald-500 bg-emerald-500 text-white'
                            : m.requested
                              ? 'border-amber-400 bg-amber-100 dark:bg-amber-900/40'
                              : 'border-muted-foreground/30'
                    "
                >
                    <Check v-if="m.done" class="size-3.5" />
                    <Flag v-else-if="m.requested" class="size-3 text-amber-600" />
                </span>
                <span :class="m.done && 'text-muted-foreground line-through'">{{ m.title }}</span>
                <span v-if="m.requested" class="text-xs text-amber-700 dark:text-amber-300">wartet auf Bestätigung</span>
                <button
                    v-if="goal.can_edit && goal.status === 'active' && !m.done && m === nextMilestone && (!m.requested || isParent)"
                    class="ml-auto rounded-md bg-emerald-600 px-2.5 py-1 text-xs font-bold text-white hover:bg-emerald-700 disabled:opacity-50"
                    :disabled="busy"
                    @click="post('goals.milestone', { goal: goal.id, milestone: m.id })"
                >
                    {{ isParent && m.requested ? 'Bestätigen' : 'Geschafft!' }}
                </button>
            </li>
        </ol>

        <!-- Zähl-Ziel -->
        <div v-if="goal.type === 'count' && goal.can_edit && goal.status === 'active'" class="flex items-center gap-2">
            <button
                class="rounded-md border p-1.5 hover:bg-muted disabled:opacity-50"
                :disabled="busy"
                aria-label="Eins zurück"
                @click="post('goals.count', { goal: goal.id }, { amount: -1 })"
            >
                <Minus class="size-4" />
            </button>
            <button
                class="flex items-center gap-1 rounded-md bg-sky-600 px-3 py-1.5 text-sm font-bold text-white hover:bg-sky-700 disabled:opacity-50"
                :disabled="busy"
                @click="post('goals.count', { goal: goal.id }, { amount: 1 })"
            >
                <Plus class="size-4" /> {{ goal.unit ? `1 ${goal.unit}` : 'Eins mehr' }}
            </button>
        </div>

        <p v-if="goal.tasks.length" class="text-xs text-muted-foreground">Zählt mit: {{ goal.tasks.map((t) => t.title).join(', ') }}</p>
        <p v-if="goal.reward_stars || goal.reward_wish" class="text-xs font-semibold text-amber-700 dark:text-amber-300">
            Belohnung:
            <template v-if="goal.reward_stars">⭐ {{ goal.reward_stars }}</template>
            <template v-if="goal.reward_wish"> {{ goal.reward_wish }}</template>
        </p>

        <!-- Abgelaufen: nie eine Strafe, nur eine Frage -->
        <div v-if="goal.status === 'expired' && goal.can_edit" class="grid gap-2 rounded-lg bg-amber-50 p-3 text-sm dark:bg-amber-950/40">
            <p class="font-bold">Das Enddatum ist vorbei. Wie soll es weitergehen?</p>
            <div class="flex flex-wrap items-center gap-2">
                <input v-model="extendTo" type="date" class="h-9 rounded-md border bg-background px-2 text-sm" aria-label="Neues Enddatum" />
                <button
                    class="rounded-md bg-primary px-3 py-1.5 text-xs font-bold text-primary-foreground disabled:opacity-50"
                    :disabled="busy || !extendTo"
                    @click="extend"
                >
                    Verlängern
                </button>
                <button class="rounded-md border px-3 py-1.5 text-xs font-bold hover:bg-muted" @click="emit('edit', goal)">Anpassen</button>
                <button
                    class="rounded-md border px-3 py-1.5 text-xs font-bold hover:bg-muted"
                    :disabled="busy"
                    @click="post('goals.release', { goal: goal.id })"
                >
                    Loslassen
                </button>
            </div>
        </div>

        <footer class="flex flex-wrap items-center gap-2 text-xs">
            <span v-if="goal.supporters.length" class="flex items-center gap-1 text-muted-foreground">
                <HandHeart class="size-3.5" /> Hilft dir: {{ goal.supporters.map((s) => s.name).join(', ') }}
            </span>
            <button
                v-if="goal.can_support && goal.status === 'active'"
                class="ml-auto flex items-center gap-1 rounded-full border px-3 py-1 font-semibold hover:bg-muted"
                :class="goal.is_supporter && 'border-rose-300 bg-rose-50 dark:bg-rose-950/40'"
                :disabled="busy"
                @click="post('goals.support', { goal: goal.id }, goal.is_supporter ? { off: 1 } : {})"
            >
                <HandHeart class="size-3.5" /> {{ goal.is_supporter ? 'Du hilfst' : 'Ich helfe dir' }}
            </button>
            <span v-if="goal.status === 'reached'" class="ml-auto font-bold text-amber-700 dark:text-amber-300"
                >🏆 Erreicht am {{ goal.reached_at && new Date(goal.reached_at).toLocaleDateString('de-DE') }}</span
            >
        </footer>
    </article>
</template>
