<script setup lang="ts">
import Emoji from '@/components/game/Emoji.vue';
import type { SharedData } from '@/types';
import { router, usePage } from '@inertiajs/vue3';
import { Check } from 'lucide-vue-next';
import { computed, ref } from 'vue';

export interface VoteView {
    id: number;
    status: 'open' | 'tie' | 'decided' | 'started' | 'cancelled';
    options: { key: string; name: string; icon: string; type: string; days: number | null; description: string | null; votes: number | null }[];
    winner: string | null;
    leaders: string[];
    my_vote: string | null;
    voters: { id: number; name: string; avatar_color: string; voted: boolean }[];
    votes_cast: number;
    closes_at: string | null;
    can_manage: boolean;
}

const props = defineProps<{ vote: VoteView | null; canOpen: boolean }>();
const page = usePage<SharedData>();
const me = computed(() => page.props.auth.user.id);

// Am Familiengerät: Eltern wählen, wer gerade abstimmt
const voterId = ref<number>(me.value);
const voter = computed(() => props.vote?.voters.find((v) => v.id === voterId.value));
const busy = ref(false);
const opts = { preserveScroll: true, onFinish: () => (busy.value = false) };

function cast(option: string) {
    if (!props.vote) return;
    busy.value = true;
    router.post(
        route('votes.act', { vote: props.vote.id }),
        { option, member_id: voterId.value === me.value ? null : voterId.value },
        {
            ...opts,
            onSuccess: () => {
                const next = props.vote?.voters.find((v) => !v.voted);
                if (next && props.vote?.can_manage) voterId.value = next.id;
            },
        },
    );
}

function decide(option: string) {
    if (!props.vote) return;
    busy.value = true;
    router.post(route('votes.act', { vote: props.vote.id }), { decide: option }, opts);
}

function close() {
    if (!props.vote || !confirm('Abstimmung jetzt beenden?')) return;
    busy.value = true;
    router.post(route('votes.act', { vote: props.vote.id }), { close: true }, opts);
}

function open() {
    busy.value = true;
    router.post(route('votes.store'), {}, opts);
}

const statusText = computed(() => {
    const v = props.vote;
    if (!v) return '';
    const winner = v.options.find((o) => o.key === v.winner)?.name;
    return {
        open: `${v.votes_cast} von ${v.voters.length} haben abgestimmt.`,
        tie: 'Gleichstand! Die Eltern entscheiden zwischen den Quests vorne.',
        decided: `Gewählt: ${winner}. Sie startet, sobald die laufende Familien-Quest vorbei ist.`,
        started: `Gewählt: ${winner}. Die Quest hat begonnen!`,
        cancelled: 'Die gewählte Quest konnte nicht starten.',
    }[v.status];
});
</script>

<template>
    <div id="vote" class="grid gap-3">
        <template v-if="vote">
            <p class="text-sm font-semibold">{{ statusText }}</p>

            <div v-if="vote.status === 'open'" class="flex flex-wrap items-center gap-1.5">
                <template v-if="vote.can_manage">
                    <span class="text-xs font-semibold text-muted-foreground">Wer stimmt ab?</span>
                    <button
                        v-for="v in vote.voters"
                        :key="v.id"
                        class="flex items-center gap-1 rounded-full border-2 px-2 py-0.5 text-xs font-bold"
                        :class="voterId === v.id ? 'border-primary bg-primary/10' : 'border-transparent bg-muted'"
                        :disabled="v.voted"
                        @click="voterId = v.id"
                    >
                        <span class="size-2.5 rounded-full" :style="{ background: v.avatar_color }" /> {{ v.name }}
                        <Check v-if="v.voted" class="size-3 text-emerald-600" />
                    </button>
                </template>
                <template v-else>
                    <span v-for="v in vote.voters" :key="v.id" class="flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 text-xs font-bold">
                        {{ v.name }} <Check v-if="v.voted" class="size-3 text-emerald-600" />
                    </span>
                </template>
            </div>

            <div class="grid gap-2 sm:grid-cols-3">
                <div
                    v-for="o in vote.options"
                    :key="o.key"
                    class="flex flex-col items-center gap-1 rounded-md border-2 p-3 text-center"
                    :class="[
                        vote.winner === o.key || vote.my_vote === o.key
                            ? 'border-primary bg-primary/10'
                            : 'border-[var(--parchment-edge)] bg-background/60',
                        vote.status === 'tie' && !vote.leaders.includes(o.key) ? 'opacity-50' : '',
                    ]"
                >
                    <span class="text-4xl"><Emoji :glyph="o.icon" /></span>
                    <p class="font-extrabold leading-tight">{{ o.name }}</p>
                    <p class="text-xs text-muted-foreground">
                        {{ o.type === 'boss' ? 'Boss-Quest' : 'Sammel-Quest' }}<template v-if="o.days"> · {{ o.days }} Tage</template>
                    </p>
                    <p v-if="o.votes !== null" class="text-sm font-black">{{ o.votes }} {{ o.votes === 1 ? 'Stimme' : 'Stimmen' }}</p>
                    <button
                        v-if="vote.status === 'open' && voter && !voter.voted"
                        class="hh-button mt-1 border-[#2c4a14] bg-primary text-primary-foreground"
                        :disabled="busy"
                        @click="cast(o.key)"
                    >
                        {{ voterId === me ? 'Meine Stimme' : `Stimme von ${voter.name}` }}
                    </button>
                    <button
                        v-if="vote.status === 'tie' && vote.can_manage && vote.leaders.includes(o.key)"
                        class="hh-button mt-1 border-[var(--wood-edge)] bg-[var(--gold)] text-[var(--wood-edge)]"
                        :disabled="busy"
                        @click="decide(o.key)"
                    >
                        Diese nehmen
                    </button>
                </div>
            </div>
            <div v-if="vote.status === 'open' && vote.can_manage">
                <button class="text-xs font-semibold text-muted-foreground underline" :disabled="busy" @click="close">Abstimmung beenden</button>
            </div>
        </template>
        <template v-else>
            <p class="text-sm text-muted-foreground">Gerade läuft keine Abstimmung.</p>
            <div v-if="canOpen">
                <button class="hh-button border-[#2c4a14] bg-primary text-primary-foreground" :disabled="busy" @click="open">
                    Über die nächste Familien-Quest abstimmen
                </button>
            </div>
        </template>
    </div>
</template>
