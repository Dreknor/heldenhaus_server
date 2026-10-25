<script setup lang="ts">
import CronDialog from '@/components/game/CronDialog.vue';
import DailySupport from '@/components/game/DailySupport.vue';
import GamePanel from '@/components/game/GamePanel.vue';
import HeroScene from '@/components/game/HeroScene.vue';
import QuestCard, { type ActiveQuest } from '@/components/game/QuestCard.vue';
import StatsPanel from '@/components/game/StatsPanel.vue';
import TaskCard from '@/components/game/TaskCard.vue';
import TaskDialog from '@/components/game/TaskDialog.vue';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import type { AssignedTask, SharedData, Task, TaskType } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import {
    CalendarCheck,
    Coins,
    Hand,
    House,
    ListTodo,
    Lock,
    Pencil,
    Plus,
    Radio,
    Repeat,
    ScrollText,
    ShoppingBag,
    Skull,
    Star,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{
    habits: AssignedTask[];
    dailies: AssignedTask[];
    todos: AssignedTask[];
    inbox: (AssignedTask & { status: string | null })[];
    support: {
        low_energy_today: boolean;
        low_energy_left: number;
        low_energy_per_week: number;
        focus_mode_default: boolean;
        timer_style: 'disc' | 'digits';
        max_steps: number;
    };
    focus: {
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
    focusMembers: { id: number; name: string }[];
    recoveries: { id: number; task: string | null; assignment_id: number; needed: number; done: number; streak: number; expires_on: string }[];
    rewards: Task[];
    pool: Task[];
    cronPending: { assignment_id: number; title: string }[];
    quests: { family: ActiveQuest | null; solo: ActiveQuest | null };
    companion: { egg_key: string; potion_key: string; is_mount: boolean; name: string } | null;
    categories: Record<string, string>;
    difficulties: Record<string, number>;
    sceneBackground?: string | null;
    sceneWeather?: { type: 'clear' | 'cloudy' | 'fog' | 'rain' | 'snow' | 'storm'; label: string; is_day: boolean } | null;
    hub: {
        dungeon: {
            unlocked: boolean;
            unlockLevel: number;
            active: { theme: string; icon: string; kind: string; rooms: number; revealed: number; bossUnlocked: boolean } | null;
        };
        garden: { unlocked: boolean; unlockLevel: number; planted: number; ready: number };
        homeAssistant: { connected: boolean; lastEventAt: string | null };
    };
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

const isParent = computed(() => page.props.auth.user?.family_role === 'parent');

function ago(iso: string | null): string {
    if (!iso) return '';
    const minutes = Math.round((Date.now() - new Date(iso).getTime()) / 60000);
    if (minutes < 1) return 'gerade eben';
    if (minutes < 60) return `vor ${minutes} Min.`;
    if (minutes < 60 * 24) return `vor ${Math.round(minutes / 60)} Std.`;
    return `vor ${Math.round(minutes / 1440)} Tagen`;
}

const gardenSlots = [
    { x: 8, y: 52 },
    { x: 26, y: 52 },
    { x: 8, y: 74 },
    { x: 26, y: 74 },
    { x: 44, y: 74 },
];

const greeting = computed(() => {
    const h = new Date().getHours();
    if (h < 11) return 'Guten Morgen';
    if (h < 18) return 'Hallo';
    return 'Guten Abend';
});
</script>

<template>
    <Head title="Helden-Board" />

    <AppLayout :breadcrumbs="[{ title: 'Helden-Board', href: '/dashboard' }]">
        <div class="flex flex-1 flex-col gap-5 p-4 md:p-6">
            <!-- Kopf -->
            <HeroScene
                v-if="page.props.auth.appearance"
                :appearance="page.props.auth.appearance"
                :companion="companion"
                :background="sceneBackground"
                :weather="sceneWeather"
                :hurt="(stats?.hp ?? 50) < 15"
            >
                <div class="inline-block rounded-xl bg-white/85 px-3 py-2 text-left shadow-sm backdrop-blur dark:bg-slate-900/80">
                    <h1 class="text-xl font-extrabold tracking-tight sm:text-2xl">{{ greeting }}, {{ page.props.auth.user.name }}!</h1>
                    <p class="text-sm text-muted-foreground">
                        <template v-if="dailiesDone.total"
                            >Heute {{ dailiesDone.done }} von {{ dailiesDone.total }} täglichen Aufgaben geschafft.</template
                        >
                        <template v-else>Heute stehen keine täglichen Aufgaben an.</template>
                    </p>
                </div>
            </HeroScene>
            <StatsPanel v-if="stats" :stats="stats" class="w-full md:hidden" />

            <div class="grid gap-5 xl:grid-cols-12">
                <!-- Linke Spalte: Helden-Board -->
                <div class="flex flex-col gap-5 xl:col-span-8">
                    <GamePanel title="Helden-Board" subtitle="Gewohnheiten, tägliche Aufgaben und To-dos" :icon="ScrollText">
                        <div class="grid gap-4 md:grid-cols-3">
                            <section v-for="col in columns" :key="col.type" class="flex flex-col gap-2">
                                <div class="flex items-center justify-between border-b-2 border-dashed border-[var(--parchment-edge)] pb-1">
                                    <h3 class="flex items-center gap-1.5 font-pixel text-base">
                                        <component :is="col.icon" class="size-4 text-muted-foreground" /> {{ col.title }}
                                    </h3>
                                    <button class="rounded-md p-1 hover:bg-muted" :aria-label="`${col.title} hinzufügen`" @click="openNew(col.type)">
                                        <Plus class="size-4" />
                                    </button>
                                </div>
                                <form @submit.prevent="quickAdd(col.type)">
                                    <Input v-model="quick[col.type]" :placeholder="col.placeholder" class="h-9 bg-background/60" />
                                </form>
                                <TaskCard v-for="task in col.items" :key="task.assignment_id" :task="task" @edit="openEdit" />
                                <p
                                    v-if="!col.items.length"
                                    class="rounded-md border-2 border-dashed border-[var(--parchment-edge)] p-3 text-center text-sm text-muted-foreground"
                                >
                                    Noch nichts hier.
                                </p>
                            </section>
                        </div>
                    </GamePanel>

                    <!-- Aufgabenbörse -->
                    <GamePanel v-if="pool.length" title="Auftragsbrett" subtitle="Wer zuerst kommt, bekommt die Sterne!" :icon="Hand">
                        <div class="grid gap-2 sm:grid-cols-2">
                            <div
                                v-for="task in pool"
                                :key="task.id"
                                class="flex items-center gap-3 rounded-md border-2 border-[var(--parchment-edge)] bg-background/50 p-2.5"
                            >
                                <div class="min-w-0 flex-1">
                                    <p class="truncate font-bold">{{ task.title }}</p>
                                    <p class="flex items-center gap-1 text-xs font-bold text-amber-700 dark:text-amber-400">
                                        <Star class="size-3 fill-current" /> {{ task.star_reward }} Sterne
                                    </p>
                                </div>
                                <button
                                    class="hh-button border-[#2c4a14] bg-primary text-primary-foreground hover:brightness-110"
                                    @click="claim(task)"
                                >
                                    Ich mach's!
                                </button>
                            </div>
                        </div>
                    </GamePanel>

                    <!-- Garten & Haus -->
                    <GamePanel
                        tone="meadow"
                        title="Haus & Garten"
                        :subtitle="
                            hub.garden.unlocked
                                ? hub.garden.ready
                                    ? `${hub.garden.ready} Beet${hub.garden.ready === 1 ? '' : 'e'} erntereif!`
                                    : `${hub.garden.planted} Beet${hub.garden.planted === 1 ? '' : 'e'} bepflanzt`
                                : `Der Garten öffnet sich ab Level ${hub.garden.unlockLevel}`
                        "
                        :icon="House"
                        href="/housing"
                    >
                        <div
                            class="relative h-40 overflow-hidden rounded-md border-[3px] border-[#2c4a14] bg-[#a4d36a] bg-[radial-gradient(circle_at_20%_30%,rgb(255_255_255/0.18)_0_2px,transparent_3px),radial-gradient(circle_at_70%_60%,rgb(0_80_0/0.12)_0_2px,transparent_3px)] bg-[length:22px_22px,30px_30px] sm:h-48"
                        >
                            <div class="absolute inset-x-0 top-0 h-[34%] bg-gradient-to-b from-sky-300 to-sky-200" />
                            <div class="absolute inset-x-0 top-[34%] h-2 bg-[repeating-linear-gradient(90deg,#8a5a2b_0_6px,transparent_6px_14px)]" />
                            <img src="/images/scene/fir.webp" alt="" class="absolute left-[2%] top-[6%] h-[34%]" />
                            <img src="/images/scene/tree.webp" alt="" class="absolute left-[56%] top-[8%] h-[36%]" />
                            <img
                                src="/images/scene/house.webp"
                                alt=""
                                class="absolute right-[4%] top-[14%] h-[55%] drop-shadow-[0_4px_0_rgb(0_0_0/0.2)]"
                            />
                            <div
                                v-for="(slot, i) in gardenSlots"
                                :key="i"
                                class="absolute flex size-[18%] max-h-14 max-w-20 items-center justify-center rounded-sm border-2 border-[#5b3a1e] bg-[#7a4f2a] bg-[repeating-linear-gradient(0deg,rgb(0_0_0/0.15)_0_2px,transparent_2px_7px)]"
                                :style="{ left: `${slot.x}%`, top: `${slot.y}%` }"
                            >
                                <img v-if="i < hub.garden.planted" src="/images/scene/tulip.webp" alt="" class="h-3/4" />
                            </div>
                            <div v-if="!hub.garden.unlocked" class="absolute inset-0 flex items-center justify-center bg-black/35">
                                <Lock class="size-8 text-white drop-shadow" />
                            </div>
                        </div>
                    </GamePanel>
                </div>

                <!-- Rechte Spalte -->
                <div class="flex flex-col gap-5 xl:col-span-4">
                    <GamePanel
                        tone="wood"
                        title="Dungeon"
                        :subtitle="
                            hub.dungeon.active
                                ? `${hub.dungeon.active.icon} ${hub.dungeon.active.theme} · ${hub.dungeon.active.revealed} von ${hub.dungeon.active.rooms} Räumen erkundet`
                                : hub.dungeon.unlocked
                                  ? 'Heute wartet ein neues Gewölbe.'
                                  : `Ab Level ${hub.dungeon.unlockLevel} öffnet sich das Tor.`
                        "
                        :icon="Skull"
                        href="/dungeons"
                        :link-label="hub.dungeon.active ? 'Weiter' : 'Betreten'"
                    >
                        <div class="relative overflow-hidden rounded-md border-[3px] border-[var(--wood-edge)] bg-[#17110c]">
                            <img
                                src="/images/ui/dungeon-teaser.webp"
                                alt=""
                                class="w-full [filter:brightness(0.62)_saturate(0.75)_sepia(0.25)]"
                                :class="{ grayscale: !hub.dungeon.unlocked }"
                            />
                            <div
                                class="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_at_50%_55%,rgb(255_170_60/0.28),transparent_60%)]"
                            />
                            <div v-if="hub.dungeon.active" class="absolute inset-x-2 bottom-2">
                                <div class="h-2.5 overflow-hidden rounded-sm border border-black bg-black/60">
                                    <div
                                        class="h-full bg-gradient-to-r from-amber-500 to-yellow-300"
                                        :style="{ width: `${(hub.dungeon.active.revealed / Math.max(1, hub.dungeon.active.rooms)) * 100}%` }"
                                    />
                                </div>
                            </div>
                            <div v-if="!hub.dungeon.unlocked" class="absolute inset-0 flex items-center justify-center">
                                <Lock class="size-9 text-amber-200 drop-shadow" />
                            </div>
                        </div>
                    </GamePanel>

                    <GamePanel v-if="quests.family || quests.solo" tone="wood" title="Aktive Quests" :icon="ScrollText" href="/quests">
                        <QuestCard v-if="quests.family" :quest="quests.family" compact />
                        <QuestCard v-if="quests.solo" :quest="quests.solo" compact />
                    </GamePanel>

                    <!-- Persönliche Belohnungen -->
                    <GamePanel title="Belohnungen" subtitle="Für dein verdientes Gold" :icon="ShoppingBag" href="/shop" link-label="Shop">
                        <form @submit.prevent="quickAdd('reward')">
                            <Input v-model="quick.reward" placeholder="Eigene Belohnung …" class="h-9 bg-background/60" />
                        </form>
                        <div
                            v-for="reward in rewards"
                            :key="reward.id"
                            class="group flex items-center gap-2 rounded-md border-2 border-[var(--parchment-edge)] bg-background/50 p-2"
                        >
                            <p class="min-w-0 flex-1 truncate font-bold">{{ reward.title }}</p>
                            <button class="opacity-0 transition group-hover:opacity-60" aria-label="Bearbeiten" @click="openEdit(reward)">
                                <Pencil class="size-3.5" />
                            </button>
                            <button
                                class="hh-button border-[#7a4a06] bg-[var(--gold)] text-[var(--wood-edge)] hover:brightness-110"
                                :disabled="(stats?.gold ?? 0) < reward.gold_cost"
                                @click="buy(reward)"
                            >
                                <Coins class="size-4" /> {{ reward.gold_cost }}
                            </button>
                        </div>
                        <button
                            class="flex items-center gap-1 self-start text-xs font-bold text-muted-foreground hover:text-foreground"
                            @click="openNew('reward')"
                        >
                            <Plus class="size-3.5" /> Belohnung mit Details anlegen
                        </button>
                    </GamePanel>

                    <GamePanel
                        tone="sky"
                        title="Home Assistant"
                        :icon="Radio"
                        :href="isParent ? '/family/settings' : undefined"
                        link-label="Einrichten"
                    >
                        <div class="flex items-center gap-3 rounded-md border-2 border-[#2f5c6e]/40 bg-white/40 p-2.5 dark:bg-black/20">
                            <span class="text-2xl" aria-hidden="true">🏠</span>
                            <div class="min-w-0 flex-1">
                                <p class="font-bold">{{ hub.homeAssistant.connected ? 'Haus-Schnittstelle aktiv' : 'Noch nicht verbunden' }}</p>
                                <p class="text-xs text-[var(--panel-sub)]">
                                    <template v-if="hub.homeAssistant.lastEventAt">Letzte Meldung {{ ago(hub.homeAssistant.lastEventAt) }}</template>
                                    <template v-else-if="hub.homeAssistant.connected">Wartet auf die erste Meldung</template>
                                    <template v-else>Licht, NFC-Tags und Sensoren können Aufgaben abhaken.</template>
                                </p>
                            </div>
                            <span
                                class="relative h-6 w-11 shrink-0 rounded-full border-2 border-black/40"
                                :class="hub.homeAssistant.connected ? 'bg-emerald-500' : 'bg-slate-400'"
                                :title="hub.homeAssistant.connected ? 'Verbunden' : 'Getrennt'"
                            >
                                <span
                                    class="absolute top-0.5 size-4 rounded-full bg-white shadow transition-all"
                                    :class="hub.homeAssistant.connected ? 'left-[calc(100%-1.15rem)]' : 'left-0.5'"
                                />
                            </span>
                        </div>
                    </GamePanel>
                </div>
            </div>

            <DailySupport
                :inbox="inbox"
                :support="support"
                :focus="focus"
                :focus-members="focusMembers"
                :focus-tasks="[...todos, ...dailies]"
                :categories="categories"
            />
        </div>

        <TaskDialog
            v-model:open="dialogOpen"
            :task="editTask"
            :type="dialogType"
            scope="personal"
            :categories="categories"
            :difficulties="difficulties"
            :max-steps="support.max_steps"
        />
        <CronDialog :open="cronPending.length > 0" :tasks="cronPending" />
    </AppLayout>
</template>
