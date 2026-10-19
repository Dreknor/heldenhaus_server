<script setup lang="ts">
import { newUuid, repeatLabel, valueColor } from '@/lib/game';
import type { AssignedTask } from '@/types';
import { router } from '@inertiajs/vue3';
import { Check, Clock, Flame, Home, Minus, Pencil, Plus, Star } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{ task: AssignedTask }>();
const emit = defineEmits<{ edit: [task: AssignedTask] }>();

const busy = ref(false);
const pop = ref(false);

const isFamily = computed(() => props.task.scope === 'family');
const notDue = computed(() => props.task.type === 'daily' && props.task.due_today === false);
const canEdit = computed(() => !isFamily.value);

function score(direction: 'up' | 'down') {
    if (busy.value) return;
    busy.value = true;
    if (direction === 'up') {
        pop.value = true;
        setTimeout(() => (pop.value = false), 500);
    }
    router.post(
        route('assignments.score', { assignment: props.task.assignment_id, direction }),
        { client_uuid: newUuid() },
        { preserveScroll: true, onFinish: () => (busy.value = false) },
    );
}

function toggle() {
    score(props.task.completed ? 'down' : 'up');
}
</script>

<template>
    <div
        class="group relative flex items-stretch overflow-hidden rounded-xl border border-l-4 shadow-sm transition"
        :class="[task.completed ? 'border-l-slate-300 bg-muted/60 opacity-70' : valueColor(task.value), notDue && !task.completed ? 'opacity-60' : '']"
    >
        <!-- Gewohnheit: Plus -->
        <button
            v-if="task.type === 'habit' && task.up"
            class="flex w-11 shrink-0 items-center justify-center bg-black/5 transition hover:bg-emerald-500 hover:text-white active:scale-95 dark:bg-white/5"
            :disabled="busy"
            aria-label="Gut gemacht"
            @click="score('up')"
        >
            <Plus class="size-5" />
        </button>

        <!-- Daily / To-do: Abhaken -->
        <button
            v-if="task.type === 'daily' || task.type === 'todo'"
            class="flex w-12 shrink-0 items-center justify-center bg-black/5 transition hover:bg-black/10 dark:bg-white/5"
            :disabled="busy"
            :aria-label="task.completed ? 'Zurücknehmen' : 'Erledigt'"
            @click="toggle"
        >
            <span
                class="flex size-7 items-center justify-center rounded-lg border-2 transition"
                :class="[task.completed ? 'border-emerald-500 bg-emerald-500 text-white' : 'border-slate-400 bg-white dark:bg-slate-900', pop ? 'scale-125' : '']"
            >
                <Check v-if="task.completed" class="size-5 stroke-[3]" />
            </span>
        </button>

        <div class="min-w-0 flex-1 px-3 py-2.5">
            <div class="flex items-start gap-2">
                <p class="flex-1 font-semibold leading-snug" :class="task.completed ? 'line-through decoration-2' : ''">{{ task.title }}</p>
                <button v-if="canEdit" class="opacity-0 transition group-hover:opacity-60 hover:!opacity-100" aria-label="Bearbeiten" @click="emit('edit', task)">
                    <Pencil class="size-3.5" />
                </button>
            </div>
            <p v-if="task.notes" class="mt-0.5 line-clamp-2 text-xs text-muted-foreground">{{ task.notes }}</p>

            <div class="mt-1.5 flex flex-wrap items-center gap-1.5 text-[11px] font-semibold">
                <span v-if="isFamily" class="flex items-center gap-1 rounded-full bg-violet-100 px-2 py-0.5 text-violet-700 dark:bg-violet-900/50 dark:text-violet-300">
                    <Home class="size-3" /> Familie
                </span>
                <span v-if="task.star_reward" class="flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300">
                    <Star class="size-3 fill-current" /> {{ task.star_reward }}
                </span>
                <span v-if="task.on_duty === false" class="rounded-full bg-slate-100 px-2 py-0.5 text-slate-600 dark:bg-slate-800 dark:text-slate-300">heute nicht dran</span>
                <span v-if="task.team" class="rounded-full bg-emerald-100 px-2 py-0.5 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300">
                    🤝 {{ task.team.done }}/{{ task.team.total }}
                </span>
                <span v-if="task.pending_approval" class="flex items-center gap-1 rounded-full bg-sky-100 px-2 py-0.5 text-sky-700 dark:bg-sky-900/40 dark:text-sky-300">
                    <Clock class="size-3" /> wartet auf Freigabe
                </span>
                <span v-if="task.type === 'daily' && task.streak > 0" class="flex items-center gap-1 text-orange-600" :title="`${task.streak} Tage am Stück`">
                    <Flame class="size-3" /> {{ task.streak }}
                </span>
                <span v-if="task.type === 'daily'" class="text-muted-foreground">{{ notDue ? 'heute frei · ' : '' }}{{ repeatLabel(task.repeat) }}</span>
                <span v-if="task.type === 'habit' && (task.counter_up || task.counter_down)" class="text-muted-foreground">
                    heute +{{ task.counter_up }}<template v-if="task.down"> / −{{ task.counter_down }}</template>
                </span>
                <span v-if="task.type === 'todo' && task.due_date" class="text-muted-foreground">bis {{ new Date(task.due_date).toLocaleDateString('de-DE') }}</span>
            </div>
        </div>

        <!-- Gewohnheit: Minus -->
        <button
            v-if="task.type === 'habit' && task.down"
            class="flex w-11 shrink-0 items-center justify-center bg-black/5 transition hover:bg-rose-500 hover:text-white active:scale-95 dark:bg-white/5"
            :disabled="busy"
            aria-label="Nicht so gut"
            @click="score('down')"
        >
            <Minus class="size-5" />
        </button>
    </div>
</template>
