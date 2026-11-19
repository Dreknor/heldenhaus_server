<script setup lang="ts">
import CouncilRecap, { type Recap } from '@/components/council/CouncilRecap.vue';
import ProposalCard, { type ProposalView } from '@/components/council/ProposalCard.vue';
import VotePanel, { type VoteView } from '@/components/council/VotePanel.vue';
import Emoji from '@/components/game/Emoji.vue';
import GamePanel from '@/components/game/GamePanel.vue';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import type { SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/vue3';
import { Landmark } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

interface Member {
    id: number;
    name: string;
    avatar_color: string;
    role: string;
    free_text: boolean;
}

const props = defineProps<{
    settings: { enabled: boolean; weekday: number; time: string; free_text: boolean; weekday_label: string };
    next_at: string;
    council: {
        id: number;
        held_on: string;
        status: string;
        step: number;
        recap: Recap;
        thanks: { from: number; to: number; reason: string; from_name: string; to_name: string }[];
        decisions: { proposal_id: number; title: string; decision: string; by: string | null }[];
        wishes: { user_id: number; kind: 'note' | 'goal'; text: string; name: string }[];
    } | null;
    preview: Recap | null;
    members: Member[];
    proposals: ProposalView[];
    vote: VoteView | null;
    history: { id: number; held_on: string; thanks: number; decisions: number; wishes: number }[];
    steps: Record<string, string>;
    thank_reasons: string[];
    wish_templates: string[];
    decline_reasons: string[];
    can_lead: boolean;
}>();

const page = usePage<SharedData>();
const me = computed(() => page.props.auth.user.id);
const busy = ref(false);
const fmtDate = (iso: string) => new Date(iso).toLocaleDateString('de-DE', { weekday: 'long', day: '2-digit', month: '2-digit' });
const fmtTime = (iso: string) => new Date(iso).toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' });

function act(data: Record<string, string | number | null>, onSuccess?: () => void) {
    busy.value = true;
    router.post(route('council.act'), data, { preserveScroll: true, onFinish: () => (busy.value = false), onSuccess });
}

const step = computed(() => props.council?.step ?? 1);
const goTo = (n: number) => act({ action: 'step', step: n });

// Wer spricht? Eltern am Familiengerät wählen, Kinder sprechen für sich.
const speakers = computed(() => (props.can_lead ? props.members : props.members.filter((m) => m.id === me.value)));
const thankFrom = ref<number>(speakers.value.find((m) => !props.council?.thanks.some((t) => t.from === m.id))?.id ?? me.value);
const thankTo = ref<number | null>(null);
const thankReason = ref('');
const fromMember = computed(() => props.members.find((m) => m.id === thankFrom.value));
watch(thankFrom, () => (thankTo.value = null));

function thank() {
    act({ action: 'thank', from_id: thankFrom.value, to_id: thankTo.value, reason: thankReason.value }, () => {
        thankReason.value = '';
        thankTo.value = null;
        const next = speakers.value.find((m) => !props.council?.thanks.some((t) => t.from === m.id));
        if (next) thankFrom.value = next.id;
    });
}

const wishFor = ref<number>(speakers.value[0]?.id ?? me.value);
const wishText = ref('');
const wishKind = ref<'note' | 'goal'>('note');
const wishMember = computed(() => props.members.find((m) => m.id === wishFor.value));
function wish() {
    act({ action: 'wish', member_id: wishFor.value, text: wishText.value, kind: wishKind.value }, () => (wishText.value = ''));
}
</script>

<template>
    <Head title="Familienrat" />

    <AppLayout
        :breadcrumbs="[
            { title: 'Familie', href: '/family' },
            { title: 'Familienrat', href: '/family/council' },
        ]"
    >
        <div class="flex min-w-0 flex-1 flex-col gap-5 p-4 md:p-6">
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="flex-1 text-2xl font-extrabold tracking-tight">Familienrat</h1>
                <span v-if="!council" class="text-sm font-semibold text-muted-foreground">
                    Nächster Rat: {{ fmtDate(next_at) }}, {{ fmtTime(next_at) }} Uhr
                </span>
            </div>

            <!-- Kein Rat: Vorschau und Start -->
            <template v-if="!council">
                <GamePanel title="Eure Woche" subtitle="Etwa 10 Minuten, am besten alle zusammen am Familiengerät. Freiwillig." :icon="Landmark">
                    <CouncilRecap v-if="preview" :recap="preview" />
                    <div class="flex flex-wrap items-center gap-3">
                        <button
                            v-if="can_lead"
                            class="hh-button border-[#2c4a14] bg-primary text-primary-foreground"
                            :disabled="busy"
                            @click="act({ action: 'start' })"
                        >
                            Familienrat starten
                        </button>
                        <p v-else class="text-sm text-muted-foreground">Den Rat eröffnen deine Eltern. Du kannst hier schon abstimmen.</p>
                    </div>
                </GamePanel>
                <GamePanel title="Abstimmung" subtitle="Welche Familien-Quest kommt als Nächstes?">
                    <VotePanel :vote="vote" :can-open="can_lead" />
                </GamePanel>
                <section v-if="history.length" class="grid gap-2">
                    <h2 class="font-extrabold">Letzte Räte</h2>
                    <div class="flex flex-wrap gap-2 text-sm">
                        <span v-for="h in history" :key="h.id" class="rounded-md border bg-card px-3 py-1.5">
                            {{ new Date(h.held_on).toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit' }) }}: {{ h.thanks }}× Danke ·
                            {{ h.decisions }} Entscheidungen · {{ h.wishes }} Wünsche
                        </span>
                    </div>
                </section>
            </template>

            <!-- Geführter Ablauf -->
            <template v-else>
                <nav class="flex flex-wrap gap-1.5" aria-label="Schritte">
                    <button
                        v-for="(label, n) in steps"
                        :key="n"
                        class="hh-button border-[var(--wood-edge)] text-xs"
                        :class="Number(n) === step ? 'bg-[var(--gold)] text-[var(--wood-edge)]' : 'bg-background'"
                        :aria-current="Number(n) === step ? 'step' : undefined"
                        :disabled="busy || !can_lead"
                        @click="goTo(Number(n))"
                    >
                        {{ n }}. {{ label }}
                    </button>
                </nav>

                <GamePanel v-if="step === 1" title="1. Rückblick" subtitle="Das habt ihr diese Woche zusammen geschafft.">
                    <CouncilRecap :recap="council.recap" />
                </GamePanel>

                <GamePanel v-else-if="step === 2" title="2. Danke-Runde" subtitle="Jede Person dankt einer anderen. Das landet im Feed.">
                    <ul v-if="council.thanks.length" class="grid gap-1 text-sm">
                        <li v-for="(t, i) in council.thanks" :key="i" class="flex items-center gap-2 font-semibold">
                            <Emoji glyph="💛" /> {{ t.from_name }} dankt {{ t.to_name }} {{ t.reason }}
                        </li>
                    </ul>
                    <form class="grid gap-2" @submit.prevent="thank">
                        <div class="flex flex-wrap items-center gap-2 text-sm font-semibold">
                            <select v-model="thankFrom" class="h-9 rounded-md border border-input bg-background px-2" aria-label="Wer dankt?">
                                <option v-for="m in speakers" :key="m.id" :value="m.id" :disabled="council.thanks.some((t) => t.from === m.id)">
                                    {{ m.name }}
                                </option>
                            </select>
                            <span>dankt</span>
                            <select v-model="thankTo" class="h-9 rounded-md border border-input bg-background px-2" aria-label="Wem?" required>
                                <option :value="null" disabled>wem?</option>
                                <option v-for="m in members.filter((x) => x.id !== thankFrom)" :key="m.id" :value="m.id">{{ m.name }}</option>
                            </select>
                        </div>
                        <div class="flex flex-wrap gap-1.5">
                            <button
                                v-for="r in thank_reasons"
                                :key="r"
                                type="button"
                                class="rounded-full border px-2.5 py-1 text-sm"
                                :class="thankReason === r ? 'border-primary bg-primary/10 font-bold' : 'hover:bg-muted'"
                                @click="thankReason = r"
                            >
                                {{ r }}
                            </button>
                        </div>
                        <Input
                            v-if="fromMember?.free_text"
                            v-model="thankReason"
                            maxlength="120"
                            placeholder="… oder eigener Grund (z. B. „fürs Mutmachen“)"
                        />
                        <div>
                            <button
                                type="submit"
                                class="hh-button border-[#2c4a14] bg-primary text-primary-foreground"
                                :disabled="busy || !thankTo || !thankReason"
                            >
                                Danke sagen
                            </button>
                        </div>
                    </form>
                </GamePanel>

                <GamePanel
                    v-else-if="step === 3"
                    title="3. Vorschläge entscheiden"
                    subtitle="Belohnungen und Aufgaben, die sich die Kinder wünschen."
                >
                    <div class="grid gap-2 md:grid-cols-2">
                        <ProposalCard v-for="p in proposals" :key="p.id" :proposal="p" :can-decide="can_lead" :decline-reasons="decline_reasons" />
                    </div>
                    <p v-if="!proposals.length" class="text-sm text-muted-foreground">Keine offenen Vorschläge.</p>
                    <ul v-if="council.decisions.length" class="grid gap-1 text-sm">
                        <li v-for="d in council.decisions" :key="d.proposal_id">
                            {{ d.decision === 'accepted' ? '✅' : '💬' }} {{ d.title }}<template v-if="d.by"> ({{ d.by }})</template>
                        </li>
                    </ul>
                </GamePanel>

                <GamePanel
                    v-else-if="step === 4"
                    title="4. Abstimmung"
                    subtitle="Jede Person hat eine Stimme. Bei Gleichstand entscheiden die Eltern."
                >
                    <VotePanel :vote="vote" :can-open="can_lead" />
                </GamePanel>

                <GamePanel v-else title="5. Ein Wunsch für nächste Woche" subtitle="Jede Person darf sich eine Sache wünschen.">
                    <ul v-if="council.wishes.length" class="grid gap-1 text-sm">
                        <li v-for="w in council.wishes" :key="w.user_id" class="font-semibold">
                            <Emoji :glyph="w.kind === 'goal' ? '🎯' : '📝'" /> {{ w.name }}: {{ w.text }}
                            <span class="text-xs text-muted-foreground">({{ w.kind === 'goal' ? 'Wochenziel' : 'Notiz' }})</span>
                        </li>
                    </ul>
                    <form class="grid gap-2" @submit.prevent="wish">
                        <select
                            v-model="wishFor"
                            class="h-9 max-w-56 rounded-md border border-input bg-background px-2 text-sm"
                            aria-label="Wer wünscht sich etwas?"
                        >
                            <option v-for="m in speakers" :key="m.id" :value="m.id">{{ m.name }}</option>
                        </select>
                        <div class="flex flex-wrap gap-1.5">
                            <button
                                v-for="t in wish_templates"
                                :key="t"
                                type="button"
                                class="rounded-full border px-2.5 py-1 text-sm"
                                :class="wishText === t ? 'border-primary bg-primary/10 font-bold' : 'hover:bg-muted'"
                                @click="wishText = t"
                            >
                                {{ t }}
                            </button>
                        </div>
                        <Input v-if="wishMember?.free_text" v-model="wishText" maxlength="120" placeholder="… oder eigener Wunsch" />
                        <div class="flex flex-wrap gap-3 text-sm">
                            <label class="flex items-center gap-1.5"><input v-model="wishKind" type="radio" value="note" /> als Notiz</label>
                            <label class="flex items-center gap-1.5"><input v-model="wishKind" type="radio" value="goal" /> als Wochenziel</label>
                        </div>
                        <div>
                            <button type="submit" class="hh-button border-[#2c4a14] bg-primary text-primary-foreground" :disabled="busy || !wishText">
                                Wunsch notieren
                            </button>
                        </div>
                    </form>
                </GamePanel>

                <div v-if="can_lead" class="flex flex-wrap gap-2">
                    <button v-if="step > 1" class="hh-button border-[var(--wood-edge)] bg-background" :disabled="busy" @click="goTo(step - 1)">
                        Zurück
                    </button>
                    <button
                        v-if="step < 5"
                        class="hh-button border-[#2c4a14] bg-primary text-primary-foreground"
                        :disabled="busy"
                        @click="goTo(step + 1)"
                    >
                        Weiter
                    </button>
                    <button
                        v-else
                        class="hh-button border-[var(--wood-edge)] bg-[var(--gold)] text-[var(--wood-edge)]"
                        :disabled="busy"
                        @click="act({ action: 'finish' })"
                    >
                        Familienrat beenden
                    </button>
                </div>
            </template>
        </div>
    </AppLayout>
</template>
