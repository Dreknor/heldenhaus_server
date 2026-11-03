<script setup lang="ts">
import Emoji from '@/components/game/Emoji.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, CalendarDays, Coins, Gift, Sparkles, Users } from 'lucide-vue-next';

interface WebActions {
    familyMembers?: { id: number; name: string }[];
    completions?: { id: number; task: { title: string } | null }[];
    dailies?: { id: number; task: { title: string } | null }[];
    parents?: { id: number; name: string }[];
    classes?: { key: string; name: string; icon: string }[];
}

interface EventData {
    id: number;
    name: string;
    icon: string;
    category: string;
    status: string;
    personal: boolean;
    subject: { id: number; name: string } | null;
    description: string;
    starts_at: string;
    ends_at: string;
    ending_soon: boolean;
    time_progress: number;
    modifiers: Record<string, unknown>;
    goal: null | { goal: number; points: number; percent: number; tiers: { percent: number; reached: boolean; rewards: unknown }[] };
    quest: { name: string; icon?: string } | null;
    recipes: { key: string; output: string }[];
    own: { points: number; found: Record<string, number>; badges: string[] };
    features: string[];
    advent?: { day: number; available: boolean; opened: { key: string; name: string; icon: string } | null }[];
    merchant?: { item: string; name: string; icon: string; price: number; bought: boolean }[];
    secret_help?: { mine: { for: string; date: string }[]; received_count: number; received_from: string[] | null; reveal_on: string };
    class_trial?: { original: string; class: string; until: string } | null;
    greetings?: string[];
    joker_used?: boolean;
    chapter?: { index: number; of: number; key: string };
    year_review?: {
        from: string;
        tasks_done: number;
        bosses_defeated: number;
        quests_completed: number;
        build_projects: number;
        achievements: number;
        events: number;
    };
    suggestions?: { habit?: { title: string; category: string }; steps?: string[]; inn?: boolean };
    webActions: WebActions;
}

const props = defineProps<EventData>();
const isRunning = () => ['active', 'ending'].includes(props.status);
const date = (iso: string) => new Date(iso).toLocaleDateString('de-DE', { day: 'numeric', month: 'long', year: 'numeric' });

function openDoor(day: number) {
    router.post(route('web.events.advent', { event: props.id, day }), {}, { preserveScroll: true });
}

function buy(item: string) {
    router.post(route('web.events.merchant', { event: props.id }), { item }, { preserveScroll: true });
}

const secretHelpForm = useForm({ completion_id: 0, for_user_id: 0 });
const jokerForm = useForm({ assignment_id: 0 });
const assignParentForm = useForm({ parent_id: 0, title: '' });
const classTrialForm = useForm({ class: '' });
</script>

<template>
    <Head :title="name" />

    <AppLayout
        :breadcrumbs="[
            { title: 'Ereignisse', href: '/events' },
            { title: name, href: `/events/${id}` },
        ]"
    >
        <div class="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
            <header class="flex items-start gap-4">
                <span class="text-5xl" aria-hidden="true"><Emoji :glyph="icon" /></span>
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <h1 class="text-2xl font-extrabold tracking-tight">{{ name }}</h1>
                        <span class="rounded-full bg-muted px-2.5 py-1 text-xs font-semibold">{{
                            status === 'scheduled'
                                ? 'Demnächst'
                                : status === 'ending'
                                  ? 'Endet bald'
                                  : status === 'finished'
                                    ? 'Abgeschlossen'
                                    : 'Aktiv'
                        }}</span>
                    </div>
                    <p class="mt-1 text-sm text-muted-foreground">{{ description }}</p>
                    <p class="mt-2 inline-flex items-center gap-1 text-xs text-muted-foreground">
                        <CalendarDays class="size-3.5" /> {{ date(starts_at) }} – {{ date(ends_at) }}
                    </p>
                </div>
            </header>

            <section class="grid gap-3 rounded-2xl border bg-card p-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="flex items-center gap-2 font-extrabold"><Sparkles class="size-4 text-amber-500" /> Dein Beitrag</h2>
                    <p class="text-sm font-semibold">{{ own.points }} Punkte</p>
                </div>
                <p class="text-sm text-muted-foreground">
                    Erledigte Aufgaben bringen deinem Ereignis Fortschritt und können Belohnungen freischalten.
                </p>
                <template v-if="goal">
                    <div class="flex justify-between text-xs font-semibold">
                        <span>Gemeinsam</span><span>{{ goal.points }} / {{ goal.goal }} Punkte</span>
                    </div>
                    <div
                        class="h-2 overflow-hidden rounded-full bg-muted"
                        role="progressbar"
                        :aria-valuenow="goal.percent"
                        aria-valuemin="0"
                        aria-valuemax="100"
                    >
                        <div class="h-full rounded-full bg-amber-500" :style="{ width: `${goal.percent}%` }" />
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <span
                            v-for="tier in goal.tiers"
                            :key="tier.percent"
                            class="rounded-full px-2 py-1 text-xs font-semibold"
                            :class="
                                tier.reached ? 'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-100' : 'bg-muted text-muted-foreground'
                            "
                        >
                            {{ tier.percent }} % · {{ tier.reached ? 'erreicht' : 'noch offen' }}
                        </span>
                    </div>
                </template>
                <div v-if="own.badges.length" class="flex flex-wrap gap-2">
                    <span
                        v-for="badge in own.badges"
                        :key="badge"
                        class="rounded-full bg-amber-100 px-2 py-1 text-xs font-semibold text-amber-900 dark:bg-amber-950 dark:text-amber-100"
                        >🏅 {{ badge }}</span
                    >
                </div>
            </section>

            <section v-if="features.includes('advent') && advent" class="grid gap-3">
                <div>
                    <h2 class="flex items-center gap-2 text-lg font-extrabold"><Gift class="size-5 text-red-600" /> Adventskalender</h2>
                    <p class="text-sm text-muted-foreground">Erledige zuerst eine Aufgabe des Tages. Verpasste Türchen kannst du nachholen.</p>
                </div>
                <div class="grid grid-cols-4 gap-2 sm:grid-cols-6 md:grid-cols-8">
                    <div
                        v-for="door in advent"
                        :key="door.day"
                        class="flex min-h-24 flex-col items-center justify-center gap-1 rounded-xl border bg-card p-2 text-center"
                    >
                        <template v-if="door.opened">
                            <span class="text-2xl"><Emoji :glyph="door.opened.icon" /></span>
                            <span class="text-[11px] font-semibold">{{ door.opened.name }}</span>
                        </template>
                        <template v-else-if="door.available && isRunning()">
                            <span class="text-lg font-extrabold">{{ door.day }}</span>
                            <Button size="sm" variant="outline" @click="openDoor(door.day)">Öffnen</Button>
                        </template>
                        <template v-else>
                            <span class="text-lg font-extrabold text-muted-foreground">{{ door.day }}</span>
                            <span class="text-[11px] text-muted-foreground">Noch zu</span>
                        </template>
                    </div>
                </div>
            </section>

            <section v-if="features.includes('merchant') && merchant" class="grid gap-3">
                <div>
                    <h2 class="flex items-center gap-2 text-lg font-extrabold"><Coins class="size-5 text-amber-500" /> Wandernder Händler</h2>
                    <p class="text-sm text-muted-foreground">Jedes Angebot kannst du einmal mit Gold kaufen.</p>
                </div>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <article v-for="offer in merchant" :key="offer.item" class="grid gap-2 rounded-2xl border bg-card p-4">
                        <div class="flex items-center gap-3">
                            <span class="text-3xl"><Emoji :glyph="offer.icon" /></span>
                            <div>
                                <h3 class="font-bold">{{ offer.name }}</h3>
                                <p class="text-sm text-amber-600">{{ offer.price }} Gold</p>
                            </div>
                        </div>
                        <Button :disabled="offer.bought || !isRunning()" variant="outline" @click="buy(offer.item)">
                            {{ offer.bought ? 'Schon gekauft' : 'Kaufen' }}
                        </Button>
                    </article>
                </div>
            </section>

            <section v-if="quest" class="rounded-2xl border bg-card p-4">
                <h2 class="font-extrabold">Gemeinsame Quest</h2>
                <p class="mt-1 text-sm"><Emoji :glyph="quest.icon" /> {{ quest.name }}</p>
            </section>

            <section v-if="features.includes('secret_help') && secret_help" class="grid gap-3 rounded-2xl border bg-card p-4">
                <div>
                    <h2 class="flex items-center gap-2 font-extrabold"><Users class="size-4" /> Heimlich helfen</h2>
                    <p class="text-sm text-muted-foreground">
                        Widme eine erledigte Aufgabe einem Familienmitglied. Die Überraschung wird am {{ date(secret_help.reveal_on) }} aufgelöst.
                    </p>
                </div>
                <p class="text-sm">
                    Du hast {{ secret_help.mine.length }} Aufgabe(n) verschenkt; {{ secret_help.received_count }} sind für dich bestimmt.
                </p>
                <p v-if="secret_help.received_from" class="text-sm">Geholfen haben: {{ secret_help.received_from.join(', ') || 'Niemand' }}</p>
                <form
                    v-if="isRunning() && webActions.completions?.length && webActions.familyMembers?.length"
                    class="flex flex-wrap gap-2"
                    @submit.prevent="secretHelpForm.post(route('web.events.secret-help', { event: id }), { preserveScroll: true })"
                >
                    <select v-model.number="secretHelpForm.completion_id" required class="h-10 min-w-48 rounded-md border bg-background px-3 text-sm">
                        <option :value="0" disabled>Erledigte Aufgabe wählen</option>
                        <option v-for="completion in webActions.completions" :key="completion.id" :value="completion.id">
                            {{ completion.task?.title ?? `Aufgabe #${completion.id}` }}
                        </option>
                    </select>
                    <select v-model.number="secretHelpForm.for_user_id" required class="h-10 min-w-48 rounded-md border bg-background px-3 text-sm">
                        <option :value="0" disabled>Familienmitglied wählen</option>
                        <option v-for="member in webActions.familyMembers" :key="member.id" :value="member.id">{{ member.name }}</option>
                    </select>
                    <Button :disabled="secretHelpForm.processing || !secretHelpForm.completion_id || !secretHelpForm.for_user_id"
                        >Heimlich widmen</Button
                    >
                </form>
                <p v-else-if="isRunning()" class="text-sm text-muted-foreground">
                    Für diese Aktion brauchst du eine erledigte Aufgabe und ein anderes Familienmitglied.
                </p>
            </section>

            <section v-if="features.includes('joker') && isRunning()" class="grid gap-3 rounded-2xl border bg-card p-4">
                <div>
                    <h2 class="font-extrabold">🎂 Wunsch-Joker</h2>
                    <p class="text-sm text-muted-foreground">Lass eine offene Daily heute aus, ohne Belohnung oder Schaden.</p>
                </div>
                <p v-if="greetings?.length" class="text-sm">Deine Glückwünsche: {{ greetings.join(' · ') }}</p>
                <p v-if="joker_used" class="text-sm font-semibold">Der Wunsch-Joker wurde bereits verwendet.</p>
                <form
                    v-else-if="webActions.dailies?.length"
                    class="flex flex-wrap gap-2"
                    @submit.prevent="jokerForm.post(route('web.events.joker', { event: id }), { preserveScroll: true })"
                >
                    <select v-model.number="jokerForm.assignment_id" required class="h-10 min-w-48 rounded-md border bg-background px-3 text-sm">
                        <option :value="0" disabled>Daily wählen</option>
                        <option v-for="daily in webActions.dailies" :key="daily.id" :value="daily.id">
                            {{ daily.task?.title ?? `Daily #${daily.id}` }}
                        </option>
                    </select>
                    <Button :disabled="jokerForm.processing || !jokerForm.assignment_id">Joker einsetzen</Button>
                </form>
                <p v-else-if="!joker_used" class="text-sm text-muted-foreground">Du hast keine offene Daily für den Joker.</p>
            </section>

            <section v-if="features.includes('assign_parent') && isRunning()" class="grid gap-3 rounded-2xl border bg-card p-4">
                <div>
                    <h2 class="font-extrabold">🔄 Rollentausch</h2>
                    <p class="text-sm text-muted-foreground">Gib einem Elternteil eine Familienaufgabe für heute.</p>
                </div>
                <form
                    v-if="webActions.parents?.length"
                    class="flex flex-wrap gap-2"
                    @submit.prevent="assignParentForm.post(route('web.events.assign-parent', { event: id }), { preserveScroll: true })"
                >
                    <select v-model.number="assignParentForm.parent_id" required class="h-10 min-w-48 rounded-md border bg-background px-3 text-sm">
                        <option :value="0" disabled>Elternteil wählen</option>
                        <option v-for="parent in webActions.parents" :key="parent.id" :value="parent.id">{{ parent.name }}</option>
                    </select>
                    <Input v-model="assignParentForm.title" required maxlength="120" placeholder="Aufgabe für heute" class="max-w-sm" />
                    <Button :disabled="assignParentForm.processing || !assignParentForm.parent_id || !assignParentForm.title.trim()"
                        >Aufgabe zuweisen</Button
                    >
                </form>
            </section>

            <section v-if="features.includes('class_trial') && isRunning()" class="grid gap-3 rounded-2xl border bg-card p-4">
                <div>
                    <h2 class="font-extrabold">🎭 Klassen-Probetag</h2>
                    <p class="text-sm text-muted-foreground">
                        Probiere eine andere Klasse für 24 Stunden, ohne deinen Klassenwechsel zu verbrauchen.
                    </p>
                </div>
                <p v-if="class_trial" class="text-sm">
                    Du probierst {{ class_trial.class }} bis {{ new Date(class_trial.until).toLocaleString('de-DE') }}.
                </p>
                <form
                    v-else-if="webActions.classes?.length"
                    class="flex flex-wrap gap-2"
                    @submit.prevent="classTrialForm.post(route('web.events.class-trial', { event: id }), { preserveScroll: true })"
                >
                    <select v-model="classTrialForm.class" required class="h-10 min-w-48 rounded-md border bg-background px-3 text-sm">
                        <option value="" disabled>Klasse wählen</option>
                        <option v-for="classItem in webActions.classes" :key="classItem.key" :value="classItem.key">
                            {{ classItem.icon }} {{ classItem.name }}
                        </option>
                    </select>
                    <Button :disabled="classTrialForm.processing || !classTrialForm.class">Probetag starten</Button>
                </form>
            </section>

            <section
                v-if="chapter || year_review || suggestions?.habit || suggestions?.steps?.length || suggestions?.inn"
                class="grid gap-3 rounded-2xl border bg-card p-4"
            >
                <h2 class="font-extrabold">Weitere Ereignisinhalte</h2>
                <p v-if="chapter" class="text-sm">Kapitel {{ chapter.index }} von {{ chapter.of }}: {{ chapter.key }}</p>
                <div v-if="year_review" class="grid gap-2 sm:grid-cols-3">
                    <p class="text-sm font-semibold sm:col-span-3">Jahresrückblick seit {{ date(year_review.from) }}</p>
                    <p class="text-sm">Erledigte Aufgaben: {{ year_review.tasks_done }}</p>
                    <p class="text-sm">Abgeschlossene Quests: {{ year_review.quests_completed }}</p>
                    <p class="text-sm">Besiegte Bosse: {{ year_review.bosses_defeated }}</p>
                    <p class="text-sm">Bauprojekte: {{ year_review.build_projects }}</p>
                    <p class="text-sm">Erfolge: {{ year_review.achievements }}</p>
                    <p class="text-sm">Ereignisse: {{ year_review.events }}</p>
                </div>
                <p v-if="suggestions?.habit" class="text-sm">Vorschlag für eine Gewohnheit: {{ suggestions.habit.title }}</p>
                <div v-if="suggestions?.steps?.length" class="text-sm">
                    <p class="font-semibold">Schrittideen für deine Aufgaben:</p>
                    <ul class="list-inside list-disc text-muted-foreground">
                        <li v-for="step in suggestions.steps" :key="step">{{ step }}</li>
                    </ul>
                </div>
                <p v-if="suggestions?.inn" class="text-sm">Das Gasthaus steht für Erholung und Pausen jederzeit offen.</p>
            </section>

            <Link
                :href="route('web.events.index')"
                class="inline-flex w-fit items-center gap-2 text-sm font-semibold text-muted-foreground hover:text-foreground"
            >
                <ArrowLeft class="size-4" /> Zurück zu den Ereignissen
            </Link>
        </div>
    </AppLayout>
</template>
