<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { Coins, Copy, Handshake, MessageCircle, Plus, Trash2, Trophy } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

interface ChallengeView {
    id: number;
    title: string;
    description: string | null;
    icon: string;
    tasks: { title: string; type: 'daily' | 'habit' | 'todo' }[];
    target: number;
    reward_gold: number;
    starts_on: string;
    ends_on: string;
    scope: 'family' | 'alliance';
    family: string;
    own: boolean;
    running: boolean;
    closed: boolean;
    leaderboard_enabled: boolean;
    participants: number;
    joined: boolean;
    my_score: number | null;
    completed: boolean;
    leaderboard: { name: string; family: string; avatar_color: string | null; score: number; completed: boolean; me: boolean }[] | null;
}

interface MessageView {
    id: number;
    kind: 'text' | 'preset' | 'emoji';
    text: string | null;
    hidden: boolean;
    user: { id: number; name: string; avatar_color: string | null } | null;
    family: string | null;
    mine: boolean;
    created_at: string;
}

const props = defineProps<{
    alliances: {
        active: { id: number; family: string; members: number; since: string | null }[];
        invite: { id: number; code: string; expires_at: string } | null;
    };
    challenges: ChallengeView[];
    channel: number | null;
    messages: MessageView[];
    canWriteText: boolean;
    presets: Record<string, string>;
    emojis: string[];
    isParent: boolean;
    categories: Record<string, string>;
}>();

const typeLabel = { daily: 'täglich', habit: 'Gewohnheit', todo: 'einmalig' } as const;

const join = useForm({ code: '' });
const today = new Date().toISOString().slice(0, 10);
const inAWeek = new Date(Date.now() + 6 * 86400000).toISOString().slice(0, 10);
const showChallengeForm = ref(false);
const challenge = useForm({
    title: '',
    description: '',
    icon: '🏁',
    tasks: [{ title: '', type: 'daily' as 'daily' | 'habit' | 'todo', category: 'school' }],
    target: 7,
    reward_gold: 20,
    starts_on: today,
    ends_on: inAWeek,
    scope: 'family' as 'family' | 'alliance',
    leaderboard: false,
});
const saveChallenge = () =>
    challenge.post(route('challenges.store'), {
        preserveScroll: true,
        onSuccess: () => {
            challenge.reset();
            showChallengeForm.value = false;
        },
    });

const message = ref('');
const sendMessage = (kind: 'text' | 'preset' | 'emoji', body: string) => {
    if (!body.trim()) return;
    router.post(
        route('messages.store'),
        { alliance: props.channel, kind, body },
        { preserveScroll: true, only: ['messages'], onSuccess: () => kind === 'text' && (message.value = '') },
    );
};
const switchChannel = (alliance: number | null) => router.get(route('social.index'), alliance ? { alliance } : {}, { preserveScroll: true });
const channelName = computed(() => (props.channel ? props.alliances.active.find((a) => a.id === props.channel)?.family : null));

// Neue Nachrichten ohne WebSockets: alle 30 s, nur bei sichtbarem Tab (Konzept §5.1)
let timer: ReturnType<typeof setInterval> | undefined;
onMounted(() => {
    timer = setInterval(() => document.visibilityState === 'visible' && router.reload({ only: ['messages'] }), 30000);
});
onBeforeUnmount(() => clearInterval(timer));

const copy = (text: string) => navigator.clipboard?.writeText(text);
const time = (iso: string) => new Date(iso).toLocaleString('de-DE', { weekday: 'short', hour: '2-digit', minute: '2-digit' });
</script>

<template>
    <Head title="Bündnisse & Challenges" />

    <AppLayout
        :breadcrumbs="[
            { title: 'Familie', href: '/family' },
            { title: 'Bündnisse & Challenges', href: '/social' },
        ]"
    >
        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <h1 class="text-2xl font-extrabold tracking-tight">Bündnisse & Challenges</h1>

            <!-- Challenges -->
            <section class="grid gap-3">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="flex items-center gap-2 text-lg font-extrabold"><Trophy class="size-5" /> Challenges</h2>
                    <Button v-if="isParent" variant="outline" size="sm" @click="showChallengeForm = !showChallengeForm"
                        ><Plus class="size-4" /> Neue Challenge</Button
                    >
                </div>

                <Card v-if="showChallengeForm">
                    <CardContent class="pt-6">
                        <form class="grid gap-3" @submit.prevent="saveChallenge">
                            <div class="grid gap-3 sm:grid-cols-[4rem_1fr]">
                                <div class="grid gap-1.5">
                                    <Label for="c-icon">Symbol</Label>
                                    <Input id="c-icon" v-model="challenge.icon" maxlength="4" />
                                </div>
                                <div class="grid gap-1.5">
                                    <Label for="c-title">Titel</Label>
                                    <Input id="c-title" v-model="challenge.title" placeholder="z. B. 7 Tage lesen" />
                                    <InputError :message="challenge.errors.title" />
                                </div>
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="c-desc">Beschreibung</Label>
                                <Input id="c-desc" v-model="challenge.description" />
                            </div>
                            <div class="grid gap-2">
                                <Label>Aufgaben</Label>
                                <div v-for="(t, i) in challenge.tasks" :key="i" class="flex flex-wrap gap-2">
                                    <Input v-model="t.title" class="min-w-40 flex-1" placeholder="z. B. 15 Minuten lesen" />
                                    <select v-model="t.type" class="h-9 rounded-md border border-input bg-background px-2 text-sm">
                                        <option value="daily">täglich</option>
                                        <option value="habit">Gewohnheit</option>
                                        <option value="todo">einmalig</option>
                                    </select>
                                    <select v-model="t.category" class="h-9 rounded-md border border-input bg-background px-2 text-sm">
                                        <option v-for="(label, key) in categories" :key="key" :value="key">{{ label }}</option>
                                    </select>
                                    <Button
                                        v-if="challenge.tasks.length > 1"
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        @click="challenge.tasks.splice(i, 1)"
                                        ><Trash2 class="size-4"
                                    /></Button>
                                </div>
                                <Button
                                    v-if="challenge.tasks.length < 5"
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    class="justify-self-start"
                                    @click="challenge.tasks.push({ title: '', type: 'daily', category: 'school' })"
                                >
                                    <Plus class="size-4" /> Aufgabe
                                </Button>
                                <InputError :message="challenge.errors.tasks ?? (challenge.errors as Record<string, string>)['tasks.0.title']" />
                            </div>
                            <div class="grid gap-3 sm:grid-cols-4">
                                <div class="grid gap-1.5">
                                    <Label for="c-target">Ziel (Abschlüsse)</Label>
                                    <Input id="c-target" v-model.number="challenge.target" type="number" min="1" />
                                </div>
                                <div class="grid gap-1.5">
                                    <Label for="c-gold">Gold bei Erfolg</Label>
                                    <Input id="c-gold" v-model.number="challenge.reward_gold" type="number" min="0" max="500" />
                                </div>
                                <div class="grid gap-1.5">
                                    <Label for="c-start">Start</Label>
                                    <Input id="c-start" v-model="challenge.starts_on" type="date" />
                                </div>
                                <div class="grid gap-1.5">
                                    <Label for="c-end">Ende</Label>
                                    <Input id="c-end" v-model="challenge.ends_on" type="date" />
                                    <InputError :message="challenge.errors.ends_on" />
                                </div>
                            </div>
                            <div class="flex flex-wrap items-center gap-4 text-sm">
                                <label class="flex items-center gap-2">
                                    <input v-model="challenge.scope" type="radio" value="family" class="size-4" /> nur unsere Familie
                                </label>
                                <label class="flex items-center gap-2" :class="{ 'opacity-50': !alliances.active.length }">
                                    <input
                                        v-model="challenge.scope"
                                        type="radio"
                                        value="alliance"
                                        class="size-4"
                                        :disabled="!alliances.active.length"
                                    />
                                    mit verbündeten Familien
                                </label>
                                <label class="flex items-center gap-2"
                                    ><input v-model="challenge.leaderboard" type="checkbox" class="size-4" /> Rangliste zeigen</label
                                >
                                <Button type="submit" class="ml-auto" :disabled="challenge.processing">Anlegen</Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>

                <p v-if="!challenges.length" class="text-sm text-muted-foreground">Gerade läuft keine Challenge.</p>
                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    <Card v-for="c in challenges" :key="c.id" :class="{ 'opacity-60': c.closed }">
                        <CardHeader>
                            <CardTitle class="flex items-center gap-2"
                                ><span class="text-2xl">{{ c.icon }}</span> {{ c.title }}</CardTitle
                            >
                            <CardDescription>
                                {{ c.starts_on }} bis {{ c.ends_on }} · {{ c.participants }} dabei<template v-if="!c.own">
                                    · von {{ c.family }}</template
                                >
                                <template v-if="c.scope === 'alliance'"> · mit Verbündeten</template>
                            </CardDescription>
                        </CardHeader>
                        <CardContent class="grid gap-3 text-sm">
                            <p v-if="c.description">{{ c.description }}</p>
                            <ul class="list-inside list-disc text-muted-foreground">
                                <li v-for="t in c.tasks" :key="t.title">{{ t.title }} ({{ typeLabel[t.type] }})</li>
                            </ul>
                            <p class="flex items-center gap-2 font-semibold">
                                Ziel: {{ c.target }}× erledigen
                                <span v-if="c.reward_gold" class="flex items-center gap-1 text-amber-600"
                                    ><Coins class="size-4" /> {{ c.reward_gold }}</span
                                >
                            </p>
                            <div v-if="c.joined" class="grid gap-1">
                                <div class="h-2.5 overflow-hidden rounded-full bg-muted">
                                    <div
                                        class="h-full rounded-full bg-emerald-500 transition-all"
                                        :style="{ width: `${Math.min(100, ((c.my_score ?? 0) / c.target) * 100)}%` }"
                                    />
                                </div>
                                <p class="text-xs">{{ c.completed ? 'Geschafft! 🎉' : `${c.my_score} von ${c.target}` }}</p>
                            </div>
                            <ol v-if="c.leaderboard?.length" class="divide-y rounded-xl border">
                                <li v-for="(row, i) in c.leaderboard" :key="i" class="flex items-center gap-2 p-2" :class="{ 'font-bold': row.me }">
                                    <span class="w-5 text-muted-foreground">{{ i + 1 }}.</span>
                                    <span class="size-3 rounded-full" :style="{ background: row.avatar_color ?? '#94a3b8' }" />
                                    {{ row.name }} <span v-if="c.scope === 'alliance'" class="text-xs text-muted-foreground">({{ row.family }})</span>
                                    <span class="ml-auto">{{ row.score }}{{ row.completed ? ' 🏆' : '' }}</span>
                                </li>
                            </ol>
                            <div v-if="!c.closed" class="flex flex-wrap gap-2">
                                <Button
                                    v-if="!c.joined"
                                    size="sm"
                                    @click="router.post(route('challenges.join', { challenge: c.id }), {}, { preserveScroll: true })"
                                    >Mitmachen</Button
                                >
                                <Button
                                    v-else
                                    size="sm"
                                    variant="ghost"
                                    @click="router.post(route('challenges.leave', { challenge: c.id }), {}, { preserveScroll: true })"
                                    >Verlassen</Button
                                >
                                <Button
                                    v-if="isParent && c.own"
                                    size="sm"
                                    variant="ghost"
                                    class="text-rose-600"
                                    @click="router.delete(route('challenges.destroy', { challenge: c.id }), { preserveScroll: true })"
                                    >Beenden</Button
                                >
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </section>

            <div class="grid gap-6 lg:grid-cols-[1fr_1.4fr]">
                <!-- Bündnisse -->
                <Card>
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2"><Handshake class="size-5" /> Familienbündnisse</CardTitle>
                        <CardDescription
                            >Befreundete Familien verbinden sich, wenn Eltern beider Familien zustimmen. Dann gibt es gemeinsame Challenges und
                            Nachrichten.</CardDescription
                        >
                    </CardHeader>
                    <CardContent class="grid gap-4 text-sm">
                        <ul v-if="alliances.active.length" class="divide-y rounded-xl border">
                            <li v-for="a in alliances.active" :key="a.id" class="flex items-center gap-2 p-3">
                                <span class="font-semibold">{{ a.family }}</span>
                                <span class="text-muted-foreground">{{ a.members }} Mitglieder · seit {{ a.since }}</span>
                                <button
                                    v-if="isParent"
                                    class="ml-auto rounded-md p-1 text-rose-600 hover:bg-muted"
                                    title="Bündnis beenden"
                                    @click="router.delete(route('alliances.destroy', { alliance: a.id }), { preserveScroll: true })"
                                >
                                    <Trash2 class="size-4" />
                                </button>
                            </li>
                        </ul>
                        <p v-else class="text-muted-foreground">Noch keine Bündnisse.</p>

                        <template v-if="isParent">
                            <div class="grid gap-2 rounded-xl bg-muted p-3">
                                <p class="font-semibold">Andere Familie einladen</p>
                                <div v-if="alliances.invite" class="flex items-center gap-2">
                                    <span class="font-mono text-xl font-extrabold tracking-widest">{{ alliances.invite.code }}</span>
                                    <span class="text-xs text-muted-foreground">gültig bis {{ alliances.invite.expires_at }}</span>
                                    <button
                                        class="ml-auto rounded-md p-1.5 hover:bg-background"
                                        title="Kopieren"
                                        @click="copy(alliances.invite.code)"
                                    >
                                        <Copy class="size-4" />
                                    </button>
                                </div>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    class="justify-self-start"
                                    @click="router.post(route('alliances.invite'), {}, { preserveScroll: true })"
                                >
                                    {{ alliances.invite ? 'Neuen Code erzeugen' : 'Bündnis-Code erzeugen' }}
                                </Button>
                            </div>
                            <form
                                class="flex items-end gap-2"
                                @submit.prevent="join.post(route('alliances.join'), { preserveScroll: true, onSuccess: () => join.reset() })"
                            >
                                <div class="grid flex-1 gap-1.5">
                                    <Label for="a-code">Code einer anderen Familie</Label>
                                    <Input id="a-code" v-model="join.code" class="font-mono uppercase" />
                                </div>
                                <Button type="submit" :disabled="join.processing">Verbünden</Button>
                            </form>
                        </template>
                    </CardContent>
                </Card>

                <!-- Nachrichten -->
                <Card>
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2"><MessageCircle class="size-5" /> Nachrichten</CardTitle>
                        <div class="flex flex-wrap gap-2 pt-1">
                            <Button size="sm" :variant="channel === null ? 'default' : 'outline'" @click="switchChannel(null)">Unsere Familie</Button>
                            <Button
                                v-for="a in alliances.active"
                                :key="a.id"
                                size="sm"
                                :variant="channel === a.id ? 'default' : 'outline'"
                                @click="switchChannel(a.id)"
                            >
                                mit {{ a.family }}
                            </Button>
                        </div>
                    </CardHeader>
                    <CardContent class="grid gap-3">
                        <ul class="grid max-h-96 gap-2 overflow-y-auto text-sm">
                            <li v-if="!messages.length" class="text-muted-foreground">
                                Noch keine Nachrichten{{ channelName ? ` mit ${channelName}` : '' }}.
                            </li>
                            <li v-for="m in messages" :key="m.id" class="flex flex-col" :class="m.mine ? 'items-end' : 'items-start'">
                                <span class="text-xs text-muted-foreground">
                                    {{ m.user?.name }}<template v-if="m.family"> · {{ m.family }}</template> · {{ time(m.created_at) }}
                                </span>
                                <span
                                    class="max-w-[85%] rounded-2xl px-3 py-1.5"
                                    :class="[
                                        m.mine ? 'bg-primary text-primary-foreground' : 'bg-muted',
                                        m.kind === 'emoji' ? 'bg-transparent text-3xl' : '',
                                        m.hidden ? 'italic opacity-70' : '',
                                    ]"
                                >
                                    {{ m.hidden ? 'Nachricht für die Großen' : m.text }}
                                </span>
                            </li>
                        </ul>
                        <div class="flex flex-wrap gap-1.5">
                            <button
                                v-for="(text, key) in presets"
                                :key="key"
                                class="rounded-full border px-2.5 py-1 text-xs hover:bg-muted"
                                @click="sendMessage('preset', String(key))"
                            >
                                {{ text }}
                            </button>
                        </div>
                        <div class="flex flex-wrap gap-1">
                            <button v-for="e in emojis" :key="e" class="rounded-md p-1 text-xl hover:bg-muted" @click="sendMessage('emoji', e)">
                                {{ e }}
                            </button>
                        </div>
                        <form v-if="canWriteText" class="flex gap-2" @submit.prevent="sendMessage('text', message)">
                            <Input v-model="message" maxlength="500" placeholder="Nachricht schreiben …" />
                            <Button type="submit">Senden</Button>
                        </form>
                        <p v-else class="text-xs text-muted-foreground">Im Bündnis gibt es für dich vorgefertigte Nachrichten und Emojis.</p>
                    </CardContent>
                </Card>
            </div>
        </div>
    </AppLayout>
</template>
