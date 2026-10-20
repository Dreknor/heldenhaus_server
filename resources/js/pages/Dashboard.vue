<script setup lang="ts">
import HeroScene from '@/components/game/HeroScene.vue';
import QuestCard, { type ActiveQuest } from '@/components/game/QuestCard.vue';
import CronDialog from '@/components/game/CronDialog.vue';
import StatsPanel from '@/components/game/StatsPanel.vue';
import TaskCard from '@/components/game/TaskCard.vue';
import TaskDialog from '@/components/game/TaskDialog.vue';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import type { AssignedTask, SharedData, Task, TaskType } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { CalendarCheck, Coins, Hand, ListTodo, Pencil, Plus, Repeat, ShoppingBag, Star } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{
    habits: AssignedTask[];
    dailies: AssignedTask[];
    todos: AssignedTask[];
    rewards: Task[];
    pool: Task[];
    cronPending: { assignment_id: number; title: string }[];
    quests: { family: ActiveQuest | null; solo: ActiveQuest | null };
    companion: { egg_key: string; potion_key: string; is_mount: boolean; name: string } | null;
    categories: Record<string, string>;
    difficulties: Record<string, number>;
    sceneBackground?: string | null;
}>();

const page = usePage<SharedData>();
const stats = computed(() => page.props.auth.stats);

const columns = computed(() => [
    { type: 'habit' as TaskType, title: 'Gewohnheiten', icon: Repeat, items: props.habits, placeholder: 'Neue Gewohnheit …' },
    { type: 'daily' as TaskType, title: 'Tägliche Aufgaben', icon: CalendarCheck, items: props.dailies, placeholder: 'Neue tägliche Aufgabe …' },
    { type: 'todo' as TaskType, title: 'To-dos', icon: ListTodo, items: props.todos, placeholder: 'Neues To-do …' },
]);

const dailiesDone = computed(() => {
    const due = props.dailies.filter((d) => d.due_today);
    return { done: due.filter((d) => d.completed).length, total: due.length };
});

// Dialog
const dialogOpen = ref(false);
const dialogType = ref<TaskType>('todo');
const editTask = ref<Task | null>(null);

function openNew(type: TaskType) {
    editTask.value = null;
    dialogType.value = type;
    dialogOpen.value = true;
}

function openEdit(task: Task) {
    editTask.value = task;
    dialogOpen.value = true;
}

// Schnell anlegen
const quick = useForm<Record<string, string>>({ habit: '', daily: '', todo: '', reward: '' });

function quickAdd(type: TaskType) {
    const title = quick[type].trim();
    if (!title) return;
    router.post(
        route('tasks.store'),
        {
            type,
            title,
            category: 'household',
            difficulty: 1,
            ...(type === 'reward' ? { gold_cost: 10 } : {}),
            ...(type === 'daily' ? { repeat: { type: 'daily', every: 1 } } : {}),
        },
        { preserveScroll: true, onSuccess: () => (quick[type] = '') },
    );
}

function claim(task: Task) {
    router.post(route('tasks.claim', { task: task.id }), {}, { preserveScroll: true });
}

function buy(task: Task) {
    router.post(route('tasks.buy', { task: task.id }), {}, { preserveScroll: true });
}

const greeting = computed(() => {
    const h = new Date().getHours();
    if (h < 11) return 'Guten Morgen';
    if (h < 18) return 'Hallo';
    return 'Guten Abend';
});
</script>

<template>
    <Head title="Mein Held" />

    <AppLayout :breadcrumbs="[{ title: 'Mein Held', href: '/dashboard' }]">
        <div class="flex flex-1 flex-col gap-5 p-4 md:p-6">
            <!-- Kopf -->
            <HeroScene v-if="page.props.auth.appearance" :appearance="page.props.auth.appearance" :companion="companion" :background="sceneBackground" :hurt="(stats?.hp ?? 50) < 15">
                <div class="inline-block rounded-xl bg-white/85 px-3 py-2 text-left shadow-sm backdrop-blur dark:bg-slate-900/80">
                    <h1 class="text-xl font-extrabold tracking-tight sm:text-2xl">{{ greeting }}, {{ page.props.auth.user.name }}!</h1>
                    <p class="text-sm text-muted-foreground">
                        <template v-if="dailiesDone.total">Heute {{ dailiesDone.done }} von {{ dailiesDone.total }} täglichen Aufgaben geschafft.</template>
                        <template v-else>Heute stehen keine täglichen Aufgaben an.</template>
                    </p>
                </div>
            </HeroScene>
            <StatsPanel v-if="stats" :stats="stats" class="w-full lg:hidden" />

            <QuestCard v-if="quests.family" :quest="quests.family" compact />
            <QuestCard v-if="quests.solo" :quest="quests.solo" compact />

            <!-- Aufgabenbörse -->
            <section v-if="pool.length" class="rounded-2xl border-2 border-dashed border-violet-300 bg-violet-50/60 p-4 dark:border-violet-800 dark:bg-violet-950/20">
                <h2 class="mb-3 flex items-center gap-2 font-extrabold text-violet-700 dark:text-violet-300"><Hand class="size-5" /> Aufgabenbörse – wer zuerst kommt!</h2>
                <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                    <div v-for="task in pool" :key="task.id" class="flex items-center gap-3 rounded-xl border bg-card p-3">
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-semibold">{{ task.title }}</p>
                            <p class="flex items-center gap-1 text-xs font-semibold text-amber-600"><Star class="size-3 fill-current" /> {{ task.star_reward }} Sterne</p>
                        </div>
                        <button class="rounded-lg bg-violet-600 px-3 py-1.5 text-sm font-bold text-white hover:bg-violet-700" @click="claim(task)">Ich mach's!</button>
                    </div>
                </div>
            </section>

            <!-- Spalten -->
            <div class="grid gap-5 md:grid-cols-2 xl:grid-cols-4">
                <section v-for="col in columns" :key="col.type" class="flex flex-col gap-2">
                    <div class="flex items-center justify-between">
                        <h2 class="flex items-center gap-2 text-lg font-extrabold"><component :is="col.icon" class="size-5 text-muted-foreground" /> {{ col.title }}</h2>
                        <button class="rounded-md p-1 hover:bg-muted" :aria-label="`${col.title} hinzufügen`" @click="openNew(col.type)"><Plus class="size-5" /></button>
                    </div>
                    <form @submit.prevent="quickAdd(col.type)">
                        <Input v-model="quick[col.type]" :placeholder="col.placeholder" class="bg-muted/50" />
                    </form>
                    <TaskCard v-for="task in col.items" :key="task.assignment_id" :task="task" @edit="openEdit" />
                    <p v-if="!col.items.length" class="rounded-xl border border-dashed p-4 text-center text-sm text-muted-foreground">Noch nichts hier.</p>
                </section>

                <!-- Persönliche Belohnungen -->
                <section class="flex flex-col gap-2">
                    <div class="flex items-center justify-between">
                        <h2 class="flex items-center gap-2 text-lg font-extrabold"><ShoppingBag class="size-5 text-muted-foreground" /> Belohnungen</h2>
                        <button class="rounded-md p-1 hover:bg-muted" aria-label="Belohnung hinzufügen" @click="openNew('reward')"><Plus class="size-5" /></button>
                    </div>
                    <form @submit.prevent="quickAdd('reward')">
                        <Input v-model="quick.reward" placeholder="Eigene Belohnung …" class="bg-muted/50" />
                    </form>
                    <div v-for="reward in rewards" :key="reward.id" class="group flex items-center gap-3 rounded-xl border bg-card p-3 shadow-sm">
                        <p class="min-w-0 flex-1 truncate font-semibold">{{ reward.title }}</p>
                        <button class="opacity-0 transition group-hover:opacity-60" aria-label="Bearbeiten" @click="openEdit(reward)"><Pencil class="size-3.5" /></button>
                        <button
                            class="flex items-center gap-1 rounded-lg bg-amber-500 px-3 py-1.5 text-sm font-bold text-white hover:bg-amber-600 disabled:opacity-40"
                            :disabled="(stats?.gold ?? 0) < reward.gold_cost"
                            @click="buy(reward)"
                        >
                            <Coins class="size-4" /> {{ reward.gold_cost }}
                        </button>
                    </div>
                    <p class="text-xs text-muted-foreground">Echte Belohnungen gibt es im Belohnungsshop gegen Sterne.</p>
                </section>
            </div>
        </div>

        <TaskDialog
            v-model:open="dialogOpen"
            :task="editTask"
            :type="dialogType"
            scope="personal"
            :categories="categories"
            :difficulties="difficulties"
        />
        <CronDialog :open="cronPending.length > 0" :tasks="cronPending" />
    </AppLayout>
</template>
