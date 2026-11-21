<script setup lang="ts">
import Emoji from '@/components/game/Emoji.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import type { Goal, GoalTemplate, GoalType, LinkableTask } from './goals';

/**
 * Ziel anlegen oder anpassen (§14.1). Erst eine Vorlage oder „eigenes Ziel“, dann das Warum in eigenen Worten.
 * Höchstens 3 aktive Ziele – der Server prüft das, der Dialog weist vorher darauf hin.
 */
const props = defineProps<{
    open: boolean;
    goal?: Goal | null;
    templates: GoalTemplate[];
    tasks: LinkableTask[];
    ownerId?: number | null;
    ownerName?: string | null;
    canPrivate?: boolean;
    isParent?: boolean;
    full?: boolean;
}>();
const emit = defineEmits<{ 'update:open': [open: boolean] }>();

const step = ref<'pick' | 'details'>('pick');
const filter = ref<'all' | GoalType>('all');
const template = ref<GoalTemplate | null>(null);

const empty = () => ({
    user_id: props.ownerId ?? null,
    template_key: null as string | null,
    type: 'weekly' as GoalType,
    title: '',
    why: '',
    icon: '🎯',
    category: null as string | null,
    target: 5,
    unit: '',
    milestones: ['', '', ''] as string[],
    task_ids: [] as number[],
    ends_on: '',
    is_private: false,
    reward_stars: 0,
    reward_wish: '',
});
const form = useForm(empty());

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        form.clearErrors();
        if (props.goal) {
            const g = props.goal;
            Object.assign(form, {
                ...empty(),
                type: g.type,
                title: g.title,
                why: g.why,
                icon: g.icon,
                target: g.target ?? 5,
                ends_on: g.ends_on ?? '',
                task_ids: g.tasks.map((t) => t.id),
                reward_stars: g.reward_stars,
                reward_wish: g.reward_wish ?? '',
            });
            step.value = 'details';
        } else {
            Object.assign(form, empty());
            template.value = null;
            step.value = 'pick';
        }
    },
);

const shown = computed(() => props.templates.filter((t) => filter.value === 'all' || t.type === filter.value));
const typeNames: Record<GoalType, string> = { weekly: 'Wochenziel', milestone: 'Meilensteine', count: 'Zähl-Ziel' };
const typeHints: Record<GoalType, string> = {
    weekly: 'Wie oft pro Woche? Zählt automatisch über eine Aufgabe.',
    milestone: '3 bis 5 Schritte, vom Leichten zum Schweren.',
    count: 'Ein Zähler – über eine Aufgabe oder von Hand.',
};

function pick(t: GoalTemplate | null) {
    template.value = t;
    Object.assign(form, empty());
    if (t) {
        form.template_key = t.key;
        form.type = t.type;
        form.title = t.title;
        form.icon = t.icon;
        form.category = t.category;
        form.target = t.target ?? (t.type === 'weekly' ? 5 : 10);
        form.unit = t.unit ?? '';
        form.milestones = t.milestones ? [...t.milestones] : ['', '', ''];
    }
    step.value = 'details';
}

function submit() {
    const payload = (data: ReturnType<typeof empty>) => ({
        ...data,
        milestones: data.type === 'milestone' ? data.milestones.filter((m) => m.trim() !== '') : undefined,
        target: data.type === 'milestone' ? null : data.target,
        ends_on: data.ends_on || null,
        unit: data.type === 'count' ? data.unit || null : null,
        reward_wish: data.reward_wish || null,
    });

    if (props.goal) {
        form.transform((data) => {
            const p = payload(data);
            return {
                title: p.title,
                why: p.why,
                target: p.target,
                ends_on: p.ends_on,
                task_ids: p.task_ids,
                reward_stars: p.reward_stars,
                reward_wish: p.reward_wish,
            };
        }).put(route('goals.update', { goal: props.goal.id }), { preserveScroll: true, onSuccess: () => emit('update:open', false) });
    } else {
        form.transform(payload).post(route('goals.store'), { preserveScroll: true, onSuccess: () => emit('update:open', false) });
    }
}

const gameError = computed(() => (form.errors as Record<string, string | undefined>).game);
const linkable = computed(() => props.tasks.filter((t) => form.type !== 'weekly' || t.type !== 'todo'));
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-xl">
            <DialogHeader>
                <DialogTitle>{{ goal ? 'Ziel anpassen' : ownerName ? `Neues Ziel für ${ownerName}` : 'Neues Ziel' }}</DialogTitle>
                <DialogDescription v-if="!goal">
                    Höchstens drei Ziele gleichzeitig – so bleibt der Kopf frei. Weitere Ideen passen in die Kopf-Ablage.
                </DialogDescription>
            </DialogHeader>

            <p v-if="full && !goal" class="rounded-lg bg-amber-50 p-3 text-sm font-semibold dark:bg-amber-950/40">
                Es sind schon drei Ziele aktiv. Schließ erst eins ab oder lass eins los.
            </p>

            <!-- Schritt 1: Vorlage wählen -->
            <template v-else-if="step === 'pick'">
                <div class="flex flex-wrap gap-1.5">
                    <button
                        v-for="f in ['all', 'weekly', 'milestone', 'count'] as const"
                        :key="f"
                        class="rounded-full border px-3 py-1 text-xs font-semibold"
                        :class="filter === f ? 'border-primary bg-primary text-primary-foreground' : 'hover:bg-muted'"
                        @click="filter = f"
                    >
                        {{ f === 'all' ? 'Alle' : typeNames[f] }}
                    </button>
                </div>
                <div class="grid max-h-[45vh] gap-2 overflow-y-auto pr-1 sm:grid-cols-2">
                    <button
                        class="flex items-center gap-2 rounded-xl border-2 border-dashed p-3 text-left text-sm font-bold hover:bg-muted"
                        @click="pick(null)"
                    >
                        <span class="text-2xl">✏️</span> Eigenes Ziel
                    </button>
                    <button
                        v-for="t in shown"
                        :key="t.key"
                        class="flex items-center gap-2 rounded-xl border p-3 text-left text-sm hover:border-primary hover:bg-muted"
                        @click="pick(t)"
                    >
                        <span class="text-2xl"><Emoji :glyph="t.icon" /></span>
                        <span class="min-w-0">
                            <span class="block font-bold">{{ t.title }}</span>
                            <span class="text-xs text-muted-foreground">{{ typeNames[t.type] }}</span>
                        </span>
                    </button>
                </div>
            </template>

            <!-- Schritt 2: Details -->
            <form v-else class="grid gap-4" @submit.prevent="submit">
                <div v-if="!goal && !template" class="grid gap-1.5">
                    <Label>Art</Label>
                    <div class="grid grid-cols-3 gap-2">
                        <button
                            v-for="t in ['weekly', 'milestone', 'count'] as const"
                            :key="t"
                            type="button"
                            class="rounded-lg border p-2 text-xs font-bold"
                            :class="form.type === t ? 'border-primary bg-primary/10' : 'hover:bg-muted'"
                            @click="form.type = t"
                        >
                            {{ typeNames[t] }}
                        </button>
                    </div>
                    <p class="text-xs text-muted-foreground">{{ typeHints[form.type] }}</p>
                </div>

                <div class="grid gap-1.5">
                    <Label for="g-title">Ziel</Label>
                    <Input id="g-title" v-model="form.title" maxlength="120" required placeholder="z. B. Fahrrad fahren ohne Stützräder" />
                    <p v-if="form.errors.title" class="text-xs text-destructive">{{ form.errors.title }}</p>
                </div>

                <div class="grid gap-1.5">
                    <Label for="g-why">Warum ist dir das wichtig?</Label>
                    <Input id="g-why" v-model="form.why" maxlength="200" required placeholder="damit ich …" />
                    <div v-if="template?.why?.length" class="flex flex-wrap gap-1.5">
                        <button
                            v-for="w in template.why"
                            :key="w"
                            type="button"
                            class="rounded-full bg-muted px-2.5 py-1 text-xs hover:bg-primary/10"
                            @click="form.why = w"
                        >
                            {{ w }}
                        </button>
                    </div>
                    <p v-if="form.errors.why" class="text-xs text-destructive">{{ form.errors.why }}</p>
                </div>

                <div v-if="form.type === 'weekly'" class="grid gap-1.5">
                    <Label for="g-target">Wie oft pro Woche?</Label>
                    <Input id="g-target" v-model.number="form.target" type="number" min="1" max="7" class="w-24" />
                </div>
                <div v-if="form.type === 'count'" class="flex flex-wrap gap-3">
                    <div class="grid gap-1.5">
                        <Label for="g-count">Wie viele?</Label>
                        <Input id="g-count" v-model.number="form.target" type="number" min="1" max="10000" class="w-28" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="g-unit">Was wird gezählt?</Label>
                        <Input id="g-unit" v-model="form.unit" maxlength="30" placeholder="z. B. Bücher" class="w-40" />
                    </div>
                </div>
                <div v-if="form.type === 'milestone' && !goal" class="grid gap-1.5">
                    <Label>Schritte (3 bis 5)</Label>
                    <div v-for="(m, i) in form.milestones" :key="i" class="flex items-center gap-2">
                        <span class="w-5 text-right text-xs font-bold text-muted-foreground">{{ i + 1 }}.</span>
                        <Input v-model="form.milestones[i]" maxlength="120" :placeholder="`Schritt ${i + 1}`" />
                    </div>
                    <div class="flex gap-2">
                        <Button v-if="form.milestones.length < 5" type="button" variant="outline" size="sm" @click="form.milestones.push('')"
                            >+ Schritt</Button
                        >
                        <Button v-if="form.milestones.length > 3" type="button" variant="ghost" size="sm" @click="form.milestones.pop()"
                            >– Schritt</Button
                        >
                    </div>
                    <p v-if="form.errors.milestones" class="text-xs text-destructive">{{ form.errors.milestones }}</p>
                </div>

                <div v-if="form.type !== 'milestone'" class="grid gap-1.5">
                    <Label>Zählt über diese Aufgabe(n)</Label>
                    <p v-if="!linkable.length" class="text-xs text-muted-foreground">
                        Noch keine passende Aufgabe.
                        {{ form.type === 'weekly' ? 'Leg zuerst eine tägliche Aufgabe oder Gewohnheit an.' : 'Du kannst auch von Hand zählen.' }}
                    </p>
                    <div class="flex max-h-40 flex-wrap gap-1.5 overflow-y-auto">
                        <label
                            v-for="t in linkable"
                            :key="t.id"
                            class="flex cursor-pointer items-center gap-1.5 rounded-full border px-3 py-1 text-xs"
                            :class="form.task_ids.includes(t.id) && 'border-primary bg-primary/10 font-bold'"
                        >
                            <input v-model="form.task_ids" type="checkbox" :value="t.id" class="sr-only" />
                            {{ t.title }}
                        </label>
                    </div>
                    <p v-if="form.errors.task_ids" class="text-xs text-destructive">{{ form.errors.task_ids }}</p>
                </div>

                <div class="grid gap-1.5">
                    <Label for="g-end">Bis wann? (freiwillig)</Label>
                    <Input id="g-end" v-model="form.ends_on" type="date" class="w-44" />
                    <p class="text-xs text-muted-foreground">Klappt es bis dahin nicht, fragt Heldenhaus nur: verlängern, anpassen oder loslassen?</p>
                </div>

                <label v-if="canPrivate && !goal" class="flex items-center gap-2 text-sm">
                    <input v-model="form.is_private" type="checkbox" class="size-4" /> Privat – andere sehen nur, dass es ein Ziel gibt
                </label>

                <div v-if="isParent" class="grid gap-2 rounded-lg bg-muted/60 p-3">
                    <p class="text-xs font-bold">Zusätzliche Belohnung (nur Eltern)</p>
                    <div class="flex flex-wrap gap-3">
                        <div class="grid gap-1">
                            <Label for="g-stars" class="text-xs">Sterne</Label>
                            <Input id="g-stars" v-model.number="form.reward_stars" type="number" min="0" max="1000" class="w-24" />
                        </div>
                        <div class="grid flex-1 gap-1">
                            <Label for="g-wish" class="text-xs">Wunsch</Label>
                            <Input id="g-wish" v-model="form.reward_wish" maxlength="120" placeholder="z. B. Ausflug in den Kletterpark" />
                        </div>
                    </div>
                </div>

                <p v-if="gameError" class="text-sm text-destructive">{{ gameError }}</p>

                <DialogFooter class="gap-2 sm:justify-between">
                    <Button v-if="!goal" type="button" variant="ghost" @click="step = 'pick'">Zurück</Button>
                    <Button type="submit" :disabled="form.processing">{{ goal ? 'Speichern' : 'Ziel setzen' }}</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
