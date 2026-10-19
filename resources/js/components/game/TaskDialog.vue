<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { difficultyLabels, weekdays } from '@/lib/game';
import type { Member, Task, TaskType } from '@/types';
import { router, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

const props = defineProps<{
    open: boolean;
    task?: Task | null;
    type?: TaskType;
    scope?: 'personal' | 'family';
    members?: Member[];
    categories: Record<string, string>;
    difficulties: Record<string, number>;
}>();

const emit = defineEmits<{ 'update:open': [value: boolean] }>();

const typeLabels: Record<TaskType, string> = {
    habit: 'Gewohnheit',
    daily: 'Tägliche Aufgabe',
    todo: 'To-do',
    reward: 'Belohnung',
};

const editing = computed(() => !!props.task);
const currentType = computed<TaskType>(() => props.task?.type ?? props.type ?? 'todo');
const isFamily = computed(() => (props.task?.scope ?? props.scope) === 'family');

function initial() {
    const t = props.task;
    return {
        type: currentType.value as TaskType,
        scope: isFamily.value ? 'family' : 'personal',
        title: t?.title ?? '',
        notes: t?.notes ?? '',
        category: t?.category ?? 'household',
        difficulty: t?.difficulty ?? 1,
        up: t?.up ?? true,
        down: t?.down ?? false,
        repeat: t?.repeat ?? { type: 'daily' as const, every: 1, days: [1, 2, 3, 4, 5] },
        due_date: t?.due_date ?? '',
        is_private: t?.is_private ?? false,
        gold_cost: t?.gold_cost ?? 10,
        approval_mode: t?.approval_mode ?? 'none',
        assignment_mode: t?.assignment_mode ?? 'assigned',
        assignees: t?.assignment_mode === 'rotation' ? (t.rotation?.members ?? []) : (t?.assignees ?? []),
        rotation_interval: t?.rotation?.interval ?? 'daily',
        team_bonus_gold: t?.team_bonus_gold ?? 5,
        star_reward: t?.star_reward ?? 1,
        ha_event_key: t?.ha_event_key ?? '',
    };
}

const form = useForm(initial());

watch(
    () => [props.open, props.task, props.type],
    () => {
        if (props.open) {
            const values = initial();
            form.defaults(values);
            form.reset();
            form.clearErrors();
        }
    },
);

function payload() {
    const d = form.data();
    const data: Record<string, unknown> = {
        title: d.title,
        notes: d.notes || null,
        category: d.category,
        difficulty: d.difficulty,
    };
    if (!editing.value) {
        data.type = d.type;
        data.scope = d.scope;
    }
    if (d.type === 'habit') Object.assign(data, { up: d.up, down: d.down });
    if (d.type === 'daily') data.repeat = d.repeat;
    if (d.type === 'todo') data.due_date = d.due_date || null;
    if (d.type === 'reward') data.gold_cost = d.gold_cost;
    if (isFamily.value) {
        Object.assign(data, {
            approval_mode: d.approval_mode,
            assignment_mode: d.assignment_mode,
            assignees: d.assignment_mode === 'pool' ? [] : d.assignees,
            rotation_interval: d.assignment_mode === 'rotation' ? d.rotation_interval : null,
            team_bonus_gold: d.assignment_mode === 'team' ? d.team_bonus_gold : 0,
            star_reward: d.star_reward,
            ha_event_key: d.ha_event_key || null,
        });
    } else {
        data.is_private = d.is_private;
    }
    return data;
}

function submit() {
    form.transform(() => payload());
    const options = { preserveScroll: true, onSuccess: () => emit('update:open', false) };
    if (editing.value && props.task) {
        form.put(route('tasks.update', { task: props.task.id }), options);
    } else {
        form.post(route('tasks.store'), options);
    }
}

function remove() {
    if (!props.task || !confirm('Aufgabe wirklich löschen?')) return;
    router.delete(route('tasks.destroy', { task: props.task.id }), { preserveScroll: true, onSuccess: () => emit('update:open', false) });
}

function toggleDay(day: number) {
    const days = new Set(form.repeat.days ?? []);
    if (days.has(day)) days.delete(day);
    else days.add(day);
    form.repeat = { ...form.repeat, days: [...days].sort() };
}

function toggleAssignee(id: number) {
    form.assignees = form.assignees.includes(id) ? form.assignees.filter((a) => a !== id) : [...form.assignees, id];
}

const selectClass =
    'flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring';
const chip = (active: boolean) =>
    `rounded-lg border px-3 py-1.5 text-sm font-semibold transition ${active ? 'border-primary bg-primary text-primary-foreground' : 'hover:bg-muted'}`;
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>
                    {{ editing ? 'Bearbeiten' : 'Neu' }}: {{ typeLabels[currentType] }}{{ isFamily ? ' für die Familie' : '' }}
                </DialogTitle>
            </DialogHeader>

            <form class="grid gap-4" @submit.prevent="submit">
                <InputError :message="form.errors.type" />

                <div class="grid gap-1.5">
                    <Label for="title">Titel</Label>
                    <Input id="title" v-model="form.title" required autofocus placeholder="z. B. Zimmer aufräumen" />
                    <InputError :message="form.errors.title" />
                </div>

                <div class="grid gap-1.5">
                    <Label for="notes">Notiz</Label>
                    <textarea id="notes" v-model="form.notes" rows="2" :class="selectClass + ' h-auto'" />
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div class="grid gap-1.5">
                        <Label for="category">Kategorie</Label>
                        <select id="category" v-model="form.category" :class="selectClass">
                            <option v-for="(label, key) in categories" :key="key" :value="key">{{ label }}</option>
                        </select>
                    </div>
                    <div v-if="currentType === 'reward'" class="grid gap-1.5">
                        <Label for="gold_cost">Preis (Gold)</Label>
                        <Input id="gold_cost" v-model.number="form.gold_cost" type="number" min="0" />
                        <InputError :message="form.errors.gold_cost" />
                    </div>
                </div>

                <div v-if="currentType !== 'reward'" class="grid gap-1.5">
                    <Label>Schwierigkeit</Label>
                    <div class="flex flex-wrap gap-2">
                        <button v-for="value in Object.values(difficulties)" :key="value" type="button" :class="chip(form.difficulty === value)" @click="form.difficulty = value">
                            {{ difficultyLabels[value] }}
                        </button>
                    </div>
                </div>

                <!-- Gewohnheit -->
                <div v-if="currentType === 'habit'" class="flex gap-2">
                    <button type="button" :class="chip(form.up)" @click="form.up = !form.up">＋ positiv</button>
                    <button type="button" :class="chip(form.down)" @click="form.down = !form.down">− negativ</button>
                </div>

                <!-- Daily: Wiederholung -->
                <div v-if="currentType === 'daily'" class="grid gap-2">
                    <Label>Wiederholung</Label>
                    <div class="flex gap-2">
                        <button type="button" :class="chip(form.repeat.type === 'daily')" @click="form.repeat = { type: 'daily', every: 1 }">Alle X Tage</button>
                        <button type="button" :class="chip(form.repeat.type === 'weekly')" @click="form.repeat = { type: 'weekly', days: [1, 2, 3, 4, 5] }">Wochentage</button>
                    </div>
                    <div v-if="form.repeat.type === 'daily'" class="flex items-center gap-2 text-sm">
                        Alle <Input v-model.number="form.repeat.every" type="number" min="1" class="w-20" /> Tage
                    </div>
                    <div v-else class="flex flex-wrap gap-1.5">
                        <button v-for="d in weekdays" :key="d.value" type="button" :class="chip((form.repeat.days ?? []).includes(d.value))" @click="toggleDay(d.value)">
                            {{ d.label }}
                        </button>
                    </div>
                    <InputError :message="(form.errors as Record<string, string>)['repeat.days'] || form.errors.repeat" />
                </div>

                <!-- To-do -->
                <div v-if="currentType === 'todo'" class="grid gap-1.5">
                    <Label for="due">Fällig bis (optional)</Label>
                    <Input id="due" v-model="form.due_date" type="date" />
                </div>

                <!-- Persönlich: privat -->
                <label v-if="!isFamily && currentType !== 'reward'" class="flex items-center gap-2 text-sm">
                    <input v-model="form.is_private" type="checkbox" class="size-4 rounded" />
                    Privat – Eltern sehen nur, dass etwas erledigt wurde
                </label>

                <!-- Familienaufgabe -->
                <template v-if="isFamily">
                    <div class="grid gap-1.5">
                        <Label>Wer?</Label>
                        <div class="flex gap-2">
                            <button type="button" :class="chip(form.assignment_mode === 'assigned')" @click="form.assignment_mode = 'assigned'">Zuweisen</button>
                            <button v-if="currentType === 'daily'" type="button" :class="chip(form.assignment_mode === 'rotation')" @click="form.assignment_mode = 'rotation'">
                                Ämterplan
                            </button>
                            <button v-if="currentType === 'todo'" type="button" :class="chip(form.assignment_mode === 'team')" @click="form.assignment_mode = 'team'">
                                Team
                            </button>
                            <button
                                v-if="currentType === 'todo'"
                                type="button"
                                :class="chip(form.assignment_mode === 'pool')"
                                @click="form.assignment_mode = 'pool'"
                            >
                                Aufgabenbörse
                            </button>
                        </div>
                        <div v-if="form.assignment_mode !== 'pool'" class="flex flex-wrap gap-1.5">
                            <button v-for="m in members" :key="m.id" type="button" :class="chip(form.assignees.includes(m.id))" @click="toggleAssignee(m.id)">
                                <template v-if="form.assignment_mode === 'rotation' && form.assignees.includes(m.id)">{{ form.assignees.indexOf(m.id) + 1 }}. </template>{{ m.name }}
                            </button>
                        </div>
                        <p v-if="form.assignment_mode === 'pool'" class="text-xs text-muted-foreground">Wer zuerst zugreift, bekommt die Aufgabe.</p>
                        <div v-if="form.assignment_mode === 'rotation'" class="flex flex-wrap items-center gap-2 text-sm">
                            <span class="text-xs text-muted-foreground">Reihenfolge = Reihenfolge beim Antippen. Wechsel</span>
                            <button type="button" :class="chip(form.rotation_interval === 'daily')" @click="form.rotation_interval = 'daily'">täglich</button>
                            <button type="button" :class="chip(form.rotation_interval === 'weekly')" @click="form.rotation_interval = 'weekly'">wöchentlich</button>
                        </div>
                        <div v-if="form.assignment_mode === 'team'" class="flex items-center gap-2 text-sm">
                            <span class="text-xs text-muted-foreground">Alle müssen ihren Teil abhaken. Team-Bonus für jeden:</span>
                            <Input v-model.number="form.team_bonus_gold" type="number" min="0" class="w-20" /> Gold
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div class="grid gap-1.5">
                            <Label for="stars">Sterne</Label>
                            <Input id="stars" v-model.number="form.star_reward" type="number" min="0" />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="approval">Freigabe</Label>
                            <select id="approval" v-model="form.approval_mode" :class="selectClass">
                                <option value="none">Keine</option>
                                <option value="required">Eltern bestätigen</option>
                                <option value="photo">Foto + Bestätigung</option>
                            </select>
                        </div>
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="ha">Home-Assistant-Ereignis (optional)</Label>
                        <Input id="ha" v-model="form.ha_event_key" placeholder="z. B. dishwasher_done" />
                        <InputError :message="form.errors.ha_event_key" />
                    </div>
                </template>

                <DialogFooter class="gap-2 sm:justify-between">
                    <Button v-if="editing" type="button" variant="destructive" @click="remove">Löschen</Button>
                    <span v-else />
                    <Button type="submit" :disabled="form.processing">Speichern</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
