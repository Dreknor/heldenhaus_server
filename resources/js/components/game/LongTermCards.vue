<script setup lang="ts">
import Emoji from '@/components/game/Emoji.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Link, router } from '@inertiajs/vue3';
import { BellRing, Medal, Target } from 'lucide-vue-next';
import { computed, ref } from 'vue';

/**
 * Langzeitmotivation auf dem Heldenpanel (§14.1–§14.3), bewusst knapp: aktive Ziele, abgelaufene Ziele als Frage,
 * Meister-Angebote, „Klappt es noch?“, die Frage nach drei ignorierten Erinnerungen und für Eltern Ehrensache-Vorschläge.
 */
const props = defineProps<{
    goals: {
        active: {
            id: number;
            title: string;
            icon: string;
            type: string;
            progress: { done: number; target: number; percent: number };
            next_milestone: string | null;
        }[];
        expired: { id: number; title: string; icon: string }[];
        to_confirm: { goal_id: number; milestone_id: number; title: string; goal: string; owner: string }[];
    };
    mastery: { offers: { task_id: number; title: string; type: string }[]; checks: { task_id: number; title: string }[] };
    starFade: { task_id: number; title: string; stars: number; member: string; confirmed: number }[];
    tasks: { id: number; title: string; reminder_question?: boolean; remind_at?: string | null; anchor?: string | null; scope?: string }[];
    compact?: boolean;
}>();

const post = (name: string, params: Record<string, number>, data: Record<string, unknown> = {}) =>
    router.post(route(name, params), data as never, { preserveScroll: true });

const question = computed(() => props.tasks.find((t) => t.reminder_question));
const answer = ref<'time' | 'anchor' | null>(null);
const value = ref('');
function feedback(action: string, v: string | null = null) {
    if (!question.value) return;
    post('tasks.reminder.feedback', { task: question.value.id }, { action, value: v });
    answer.value = null;
    value.value = '';
}

const hasAnything = computed(
    () =>
        props.goals.active.length ||
        props.goals.expired.length ||
        props.goals.to_confirm.length ||
        props.mastery.offers.length ||
        props.mastery.checks.length ||
        props.starFade.length ||
        question.value,
);
</script>

<template>
    <div v-if="hasAnything" class="grid gap-3">
        <!-- Erinnerung dreimal ignoriert: einmal freundlich nachfragen -->
        <section v-if="question" class="hh-panel flex flex-col gap-2 bg-sky-50 p-3 text-sm dark:bg-sky-950/40">
            <p class="flex items-center gap-2 font-bold">
                <BellRing class="size-4 text-sky-600" /> Passt die Erinnerung für „{{ question.title }}“ noch?
            </p>
            <div v-if="!answer" class="flex flex-wrap gap-2">
                <Button size="sm" variant="outline" @click="answer = 'time'">Andere Zeit</Button>
                <Button size="sm" variant="outline" @click="answer = 'anchor'">Anderer Anker</Button>
                <Button size="sm" variant="outline" @click="feedback('off')">Erinnerung aus</Button>
                <Button size="sm" variant="outline" @click="feedback('pause')">2 Wochen pausieren</Button>
                <Button size="sm" variant="ghost" @click="feedback('keep')">Passt so</Button>
            </div>
            <form
                v-else
                class="flex flex-wrap items-center gap-2"
                @submit.prevent="feedback(answer === 'time' ? 'other_time' : 'other_anchor', value)"
            >
                <Input v-if="answer === 'time'" v-model="value" type="time" required class="h-9 w-32" aria-label="Neue Uhrzeit" />
                <Input
                    v-else
                    v-model="value"
                    maxlength="80"
                    required
                    class="h-9 min-w-48 flex-1"
                    placeholder="z. B. nach dem Abendessen"
                    aria-label="Neuer Anker"
                />
                <Button size="sm" type="submit">Speichern</Button>
                <Button size="sm" variant="ghost" type="button" @click="answer = null">Zurück</Button>
            </form>
        </section>

        <!-- Meistern: eine Karte, keine Pflicht -->
        <section
            v-for="o in mastery.offers"
            :key="`offer-${o.task_id}`"
            class="hh-panel hh-parchment flex flex-col gap-2 p-3 text-sm ring-2 ring-amber-300"
        >
            <p class="flex items-center gap-2 font-extrabold">
                <Medal class="size-5 text-amber-500" /> „{{ o.title }}“ sitzt! Willst du es meistern?
            </p>
            <p class="text-xs text-muted-foreground">
                Gemeistert verlässt es die tägliche Liste. Vier Wochen lang fragen wir einmal pro Woche, ob es noch klappt.
            </p>
            <div class="flex flex-wrap gap-2">
                <Button size="sm" @click="post('tasks.master', { task: o.task_id })">Meistern</Button>
                <Button v-if="o.type === 'daily'" size="sm" variant="outline" @click="post('tasks.less-often', { task: o.task_id })"
                    >Seltener (5 von 7)</Button
                >
                <Button size="sm" variant="ghost" @click="post('tasks.keep', { task: o.task_id })">Weitermachen</Button>
            </div>
        </section>
        <section v-for="c in mastery.checks" :key="`check-${c.task_id}`" class="hh-panel hh-parchment flex flex-wrap items-center gap-2 p-3 text-sm">
            <span class="min-w-0 flex-1 font-bold">🎖️ Klappt „{{ c.title }}“ noch?</span>
            <Button size="sm" @click="post('tasks.still-ok', { task: c.task_id }, { ok: 1 })">Ja</Button>
            <Button size="sm" variant="outline" @click="post('tasks.still-ok', { task: c.task_id }, { ok: 0 })">Nein, zurück auf die Liste</Button>
        </section>

        <!-- Ziele kompakt -->
        <section
            v-if="!compact && (goals.active.length || goals.expired.length || goals.to_confirm.length)"
            class="hh-panel hh-parchment flex flex-col gap-2 p-3"
        >
            <header class="flex items-center gap-2">
                <Target class="size-5 text-rose-500" />
                <h2 class="hh-panel-title flex-1">Deine Ziele</h2>
                <Link :href="route('goals.index')" class="text-xs font-bold text-primary hover:underline">Alle Ziele</Link>
            </header>
            <Link
                v-for="g in goals.active"
                :key="g.id"
                :href="route('goals.index')"
                class="flex items-center gap-2 rounded-lg p-1.5 hover:bg-muted/60"
            >
                <span class="text-xl"><Emoji :glyph="g.icon" /></span>
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-sm font-bold">{{ g.title }}</span>
                    <span class="mt-0.5 block h-1.5 overflow-hidden rounded-full bg-muted"
                        ><span class="block h-full rounded-full bg-sky-500" :style="{ width: `${Math.max(4, g.progress.percent)}%` }"
                    /></span>
                    <span v-if="g.next_milestone" class="block truncate text-xs text-muted-foreground">Nächster Schritt: {{ g.next_milestone }}</span>
                </span>
                <span class="text-xs font-semibold text-muted-foreground">{{ g.progress.done }}/{{ g.progress.target }}</span>
            </Link>
            <p v-for="g in goals.expired" :key="`exp-${g.id}`" class="rounded-lg bg-amber-50 p-2 text-xs dark:bg-amber-950/40">
                <Emoji :glyph="g.icon" /> „{{ g.title }}“: Enddatum vorbei –
                <Link :href="route('goals.index')" class="font-bold underline">verlängern, anpassen oder loslassen?</Link>
            </p>
            <p v-if="goals.to_confirm.length" class="rounded-lg bg-sky-50 p-2 text-xs dark:bg-sky-950/40">
                {{ goals.to_confirm.length }} Meilenstein(e) warten auf deine Bestätigung –
                <Link :href="route('goals.index')" class="font-bold underline">ansehen</Link>
            </p>
        </section>

        <!-- Eltern: Ehrensache vorschlagen -->
        <section v-for="s in starFade" :key="`fade-${s.task_id}`" class="hh-panel flex flex-col gap-2 bg-amber-50 p-3 text-sm dark:bg-amber-950/40">
            <p class="font-bold">🤝 {{ s.member }} hat „{{ s.title }}“ schon {{ s.confirmed }}-mal zuverlässig erledigt.</p>
            <p class="text-xs text-muted-foreground">
                Vorschlag: die {{ s.stars }} Sterne alle zwei Wochen um ein Viertel senken, bis die Aufgabe Ehrensache ist. Die Kinder erfahren es
                vorher und bekommen das Abzeichen „Verlässlich“. Nichts passiert ohne deine Entscheidung.
            </p>
            <div class="flex flex-wrap gap-2">
                <Button size="sm" @click="post('tasks.fade-stars', { task: s.task_id }, { action: 'start' })">Ausschleichen starten</Button>
                <Button size="sm" variant="ghost" @click="post('tasks.fade-stars', { task: s.task_id }, { action: 'dismiss' })">Nicht jetzt</Button>
            </div>
        </section>
    </div>
</template>
