<script setup lang="ts">
import TaskDialog from '@/components/game/TaskDialog.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { difficultyLabels, repeatLabel } from '@/lib/game';
import type { Member, Task, TaskType } from '@/types';
import { Head } from '@inertiajs/vue3';
import { CalendarCheck, Hand, ListTodo, Repeat, ShieldCheck, Star, Wifi } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{
    tasks: Task[];
    members: Member[];
    categories: Record<string, string>;
    difficulties: Record<string, number>;
}>();

const typeMeta: Record<string, { label: string; icon: unknown }> = {
    habit: { label: 'Gewohnheit', icon: Repeat },
    daily: { label: 'Täglich', icon: CalendarCheck },
    todo: { label: 'To-do', icon: ListTodo },
};

const memberName = (id: number) => props.members.find((m) => m.id === id)?.name ?? '?';
const assignable = computed(() => props.members.filter((m) => m.role !== 'guest'));

const open = ref(false);
const type = ref<TaskType>('daily');
const editing = ref<Task | null>(null);

function create(t: TaskType) {
    editing.value = null;
    type.value = t;
    open.value = true;
}

function edit(task: Task) {
    editing.value = task;
    open.value = true;
}
</script>

<template>
    <Head title="Familienaufgaben" />

    <AppLayout :breadcrumbs="[{ title: 'Familienaufgaben', href: '/family/tasks' }]">
        <div class="flex flex-1 flex-col gap-5 p-4 md:p-6">
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="flex-1 text-2xl font-extrabold tracking-tight">Familienaufgaben</h1>
                <Button variant="outline" @click="create('habit')">+ Gewohnheit</Button>
                <Button variant="outline" @click="create('todo')">+ To-do / Börse</Button>
                <Button @click="create('daily')">+ Tägliche Aufgabe</Button>
            </div>

            <div v-if="!tasks.length" class="rounded-2xl border border-dashed p-10 text-center text-muted-foreground">
                Noch keine Familienaufgaben. Lege z. B. „Spülmaschine ausräumen“ als tägliche Aufgabe an.
            </div>

            <div class="grid gap-3">
                <button
                    v-for="task in tasks"
                    :key="task.id"
                    class="flex flex-wrap items-center gap-3 rounded-xl border bg-card p-4 text-left shadow-sm transition hover:border-primary"
                    @click="edit(task)"
                >
                    <component :is="typeMeta[task.type]?.icon" class="size-5 text-muted-foreground" />
                    <div class="min-w-0 flex-1">
                        <p class="font-bold">{{ task.title }}</p>
                        <p class="text-xs text-muted-foreground">
                            {{ typeMeta[task.type]?.label }} · {{ categories[task.category] }} · {{ difficultyLabels[task.difficulty] }}
                            <template v-if="task.type === 'daily'"> · {{ repeatLabel(task.repeat) }}</template>
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2 text-xs font-semibold">
                        <span v-if="task.assignment_mode === 'pool'" class="flex items-center gap-1 rounded-full bg-violet-100 px-2 py-1 text-violet-700 dark:bg-violet-900/40 dark:text-violet-300">
                            <Hand class="size-3" /> Börse<template v-if="task.assignees.length">: {{ memberName(task.assignees[0]) }}</template>
                        </span>
                        <span v-for="id in task.assignment_mode === 'assigned' ? task.assignees : []" :key="id" class="rounded-full bg-muted px-2 py-1">{{ memberName(id) }}</span>
                        <span v-if="task.star_reward" class="flex items-center gap-1 rounded-full bg-amber-100 px-2 py-1 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300">
                            <Star class="size-3 fill-current" /> {{ task.star_reward }}
                        </span>
                        <span v-if="task.approval_mode !== 'none'" class="flex items-center gap-1 rounded-full bg-sky-100 px-2 py-1 text-sky-700 dark:bg-sky-900/40 dark:text-sky-300">
                            <ShieldCheck class="size-3" /> Freigabe
                        </span>
                        <span v-if="task.ha_event_key" class="flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-1 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300">
                            <Wifi class="size-3" /> {{ task.ha_event_key }}
                        </span>
                    </div>
                </button>
            </div>
        </div>

        <TaskDialog
            v-model:open="open"
            :task="editing"
            :type="type"
            scope="family"
            :members="assignable"
            :categories="categories"
            :difficulties="difficulties"
        />
    </AppLayout>
</template>
