<script setup lang="ts">
import Emoji from '@/components/game/Emoji.vue';
import GamePanel from '@/components/game/GamePanel.vue';
import GoalCard from '@/components/game/GoalCard.vue';
import GoalDialog from '@/components/game/GoalDialog.vue';
import type { Goal, GoalList, GoalTemplate, LinkableTask } from '@/components/game/goals';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Flag, Target, Users } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Member extends GoalList {
    id: number;
    name: string;
    is_child: boolean;
    tasks: LinkableTask[];
    templates: GoalTemplate[];
}

const props = defineProps<{
    mine: GoalList;
    members: Member[];
    family_goals: { kind: string; title: string; icon: string; href: string; percent: number; note: string | null }[];
    templates: GoalTemplate[];
    tasks: LinkableTask[];
    to_confirm: { goal_id: number; milestone_id: number; title: string; goal: string; icon: string; owner: string }[];
    can_private: boolean;
    is_parent: boolean;
}>();

const active = computed(() => props.mine.goals.filter((g) => g.status === 'active' || g.status === 'expired'));
const reached = computed(() => props.mine.goals.filter((g) => g.status === 'reached'));
const full = computed(() => props.mine.active_count >= props.mine.max_active);

// Dialog: eigenes Ziel, Ziel für ein Kind (Eltern) oder Bearbeiten
const dialog = ref(false);
const editing = ref<Goal | null>(null);
const owner = ref<Member | null>(null);

function create(member: Member | null = null) {
    editing.value = null;
    owner.value = member;
    dialog.value = true;
}
function edit(goal: Goal) {
    editing.value = goal;
    owner.value = props.members.find((m) => m.id === goal.user_id) ?? null;
    dialog.value = true;
}

const dialogTemplates = computed(() => (owner.value ? owner.value.templates : props.templates));
const dialogTasks = computed(() => (editing.value ? (owner.value?.tasks ?? props.tasks) : owner.value ? owner.value.tasks : props.tasks));
const dialogFull = computed(() => (owner.value ? owner.value.active_count >= owner.value.max_active : full.value));

const confirm = (c: (typeof props.to_confirm)[number], ok: boolean) =>
    router.post(
        route(ok ? 'goals.milestone' : 'goals.milestone.decline', { goal: c.goal_id, milestone: c.milestone_id }),
        {},
        { preserveScroll: true },
    );
</script>

<template>
    <Head title="Ziele" />

    <AppLayout :breadcrumbs="[{ title: 'Ziele', href: '/goals' }]">
        <div class="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h1 class="flex items-center gap-2 text-2xl font-extrabold tracking-tight">
                        <Target class="size-6 text-rose-500" /> Deine Ziele
                    </h1>
                    <p class="text-sm text-muted-foreground">Aufgaben sind der Weg, Ziele sind das Warum. Wochen zählen mehr als einzelne Tage.</p>
                </div>
                <div class="flex items-center gap-3">
                    <span class="rounded-2xl border bg-card px-3 py-1.5 text-sm font-bold"
                        >{{ mine.active_count }} / {{ mine.max_active }} aktiv</span
                    >
                    <Button :disabled="full" @click="create()">Neues Ziel</Button>
                </div>
            </div>

            <!-- Eltern: gemeldete Meilensteine -->
            <GamePanel
                v-if="to_confirm.length"
                title="Bitte bestätigen"
                subtitle="Die Kinder haben einen Meilenstein geschafft."
                :icon="Flag"
                tone="sky"
            >
                <ul class="grid gap-2">
                    <li
                        v-for="c in to_confirm"
                        :key="c.milestone_id"
                        class="flex flex-wrap items-center gap-2 rounded-lg bg-background/70 p-2 text-sm"
                    >
                        <Emoji :glyph="c.icon" />
                        <span class="min-w-0 flex-1"
                            ><strong>{{ c.owner }}</strong
                            >: „{{ c.title }}“ <span class="text-muted-foreground">({{ c.goal }})</span></span
                        >
                        <Button size="sm" @click="confirm(c, true)">Bestätigen</Button>
                        <Button size="sm" variant="outline" @click="confirm(c, false)">Noch nicht</Button>
                    </li>
                </ul>
            </GamePanel>

            <section class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <GoalCard v-for="g in active" :key="g.id" :goal="g" :is-parent="is_parent" @edit="edit" />
                <button
                    v-if="!active.length"
                    class="hh-panel hh-parchment flex flex-col items-center justify-center gap-2 p-8 text-center hover:ring-2 hover:ring-primary"
                    @click="create()"
                >
                    <span class="text-4xl">🎯</span>
                    <span class="font-extrabold">Setz dir dein erstes Ziel</span>
                    <span class="text-sm text-muted-foreground"
                        >Zum Beispiel: Fahrrad ohne Stützräder, 10 Bücher oder 5 von 7 Abenden Zähne putzen.</span
                    >
                </button>
            </section>

            <GamePanel v-if="family_goals.length" title="Gemeinsame Ziele" subtitle="Was ihr als Familie gerade schafft." :icon="Users" tone="meadow">
                <div class="grid gap-2 sm:grid-cols-2">
                    <Link
                        v-for="f in family_goals"
                        :key="`${f.kind}-${f.title}`"
                        :href="f.href"
                        class="flex items-center gap-3 rounded-lg bg-background/70 p-3 hover:bg-background"
                    >
                        <span class="text-2xl"><Emoji :glyph="f.icon" /></span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm font-bold">{{ f.title }}</span>
                            <span class="mt-1 block h-2 overflow-hidden rounded-full bg-muted"
                                ><span class="block h-full rounded-full bg-emerald-500" :style="{ width: `${f.percent}%` }"
                            /></span>
                            <span v-if="f.note" class="text-xs text-muted-foreground">{{ f.note }}</span>
                        </span>
                    </Link>
                </div>
            </GamePanel>

            <!-- Ziele der anderen: unterstützen, Eltern legen für Kinder an -->
            <section v-for="m in members" :key="m.id" class="grid gap-3">
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="text-lg font-extrabold">Ziele von {{ m.name }}</h2>
                    <span v-if="m.private_count" class="rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground"
                        >+ {{ m.private_count }} privat</span
                    >
                    <Button
                        v-if="is_parent && m.is_child"
                        size="sm"
                        variant="outline"
                        class="ml-auto"
                        :disabled="m.active_count >= m.max_active"
                        @click="create(m)"
                    >
                        Ziel für {{ m.name }}
                    </Button>
                </div>
                <p v-if="!m.goals.length" class="text-sm text-muted-foreground">Gerade keine sichtbaren Ziele.</p>
                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    <GoalCard v-for="g in m.goals" :key="g.id" :goal="g" :is-parent="is_parent" @edit="edit" />
                </div>
            </section>

            <details v-if="reached.length" class="hh-panel hh-parchment p-4">
                <summary class="cursor-pointer font-extrabold">🏆 Erreichte Ziele ({{ reached.length }})</summary>
                <div class="mt-3 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    <GoalCard v-for="g in reached" :key="g.id" :goal="g" />
                </div>
            </details>
        </div>

        <GoalDialog
            v-model:open="dialog"
            :goal="editing"
            :templates="dialogTemplates"
            :tasks="dialogTasks"
            :owner-id="owner?.id ?? null"
            :owner-name="owner?.name ?? null"
            :can-private="!owner && can_private"
            :is-parent="is_parent"
            :full="dialogFull"
        />
    </AppLayout>
</template>
