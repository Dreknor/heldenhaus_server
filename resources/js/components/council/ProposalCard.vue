<script setup lang="ts">
import Emoji from '@/components/game/Emoji.vue';
import { Input } from '@/components/ui/input';
import { router } from '@inertiajs/vue3';
import { Star } from 'lucide-vue-next';
import { ref } from 'vue';

export interface ProposalView {
    id: number;
    kind: 'reward' | 'task';
    target: 'pool' | 'parent' | null;
    title: string;
    icon: string | null;
    stars: number | null;
    status: 'open' | 'accepted' | 'declined';
    reason: string | null;
    user: { id: number; name: string; avatar_color: string } | null;
    for_user: { id: number; name: string } | null;
    decided_by: string | null;
    created_at: string;
}

const props = defineProps<{ proposal: ProposalView; canDecide: boolean; declineReasons: string[] }>();

const mode = ref<'view' | 'accept' | 'decline'>('view');
const title = ref(props.proposal.title);
const stars = ref<number | undefined>(props.proposal.stars ?? (props.proposal.kind === 'reward' ? 10 : 2));
const reason = ref('');
const busy = ref(false);

const kindLabel = () =>
    props.proposal.kind === 'reward'
        ? 'Belohnung'
        : props.proposal.target === 'parent'
          ? `Aufgabe für ${props.proposal.for_user?.name ?? 'Eltern'}`
          : 'Aufgabe für die Börse';

const options = { preserveScroll: true, onFinish: () => (busy.value = false), onSuccess: () => (mode.value = 'view') };

function accept() {
    busy.value = true;
    router.post(
        route('proposals.accept', { proposal: props.proposal.id }),
        { title: title.value, stars: props.proposal.target === 'parent' ? null : stars.value },
        options,
    );
}

function decline() {
    busy.value = true;
    router.post(route('proposals.decline', { proposal: props.proposal.id }), { reason: reason.value }, options);
}

const statusLabel = { open: 'wartet', accepted: 'angenommen', declined: 'nicht jetzt' } as const;
</script>

<template>
    <article class="flex flex-col gap-2 rounded-md border-2 border-[var(--parchment-edge)] bg-background/60 p-3">
        <div class="flex items-start gap-3">
            <span class="text-3xl"><Emoji :glyph="proposal.icon ?? '💡'" /></span>
            <div class="min-w-0 flex-1">
                <p class="font-extrabold leading-tight">{{ proposal.title }}</p>
                <p class="text-xs font-semibold text-muted-foreground">
                    {{ kindLabel() }}<template v-if="proposal.user"> · von {{ proposal.user.name }}</template>
                    <template v-if="proposal.stars">
                        · <Star class="inline size-3 fill-current text-amber-500" /> {{ proposal.stars }}
                        {{ proposal.kind === 'reward' ? 'Wunschpreis' : 'Sterne' }}
                    </template>
                </p>
            </div>
            <span
                class="rounded-full px-2 py-0.5 text-xs font-bold"
                :class="{
                    'bg-sky-100 text-sky-700 dark:bg-sky-900/40 dark:text-sky-300': proposal.status === 'open',
                    'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300': proposal.status === 'accepted',
                    'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200': proposal.status === 'declined',
                }"
                >{{ statusLabel[proposal.status] }}</span
            >
        </div>
        <p v-if="proposal.reason" class="rounded-md bg-muted/60 px-2 py-1 text-sm">
            💬 „{{ proposal.reason }}“<span v-if="proposal.decided_by" class="text-muted-foreground"> – {{ proposal.decided_by }}</span>
        </p>

        <template v-if="canDecide && proposal.status === 'open'">
            <div v-if="mode === 'view'" class="flex flex-wrap gap-2">
                <button class="hh-button border-[#2c4a14] bg-primary text-primary-foreground" @click="mode = 'accept'">Annehmen …</button>
                <button class="hh-button border-[var(--wood-edge)] bg-background" @click="mode = 'decline'">Nicht jetzt …</button>
            </div>
            <form v-else-if="mode === 'accept'" class="grid gap-2" @submit.prevent="accept">
                <label class="grid gap-1 text-xs font-semibold"
                    >Titel
                    <Input v-model="title" maxlength="120" required />
                </label>
                <label v-if="proposal.target !== 'parent'" class="grid gap-1 text-xs font-semibold">
                    {{ proposal.kind === 'reward' ? 'Preis im Shop (Sterne)' : 'Sterne für die Aufgabe' }}
                    <Input v-model.number="stars" type="number" :min="proposal.kind === 'reward' ? 1 : 0" max="10000" />
                </label>
                <div class="flex gap-2">
                    <button type="submit" class="hh-button border-[#2c4a14] bg-primary text-primary-foreground" :disabled="busy">Annehmen</button>
                    <button type="button" class="hh-button border-[var(--wood-edge)] bg-background" @click="mode = 'view'">Zurück</button>
                </div>
            </form>
            <form v-else class="grid gap-2" @submit.prevent="decline">
                <p class="text-xs font-semibold text-muted-foreground">Sagt freundlich, warum es gerade nicht passt:</p>
                <div class="flex flex-wrap gap-1.5">
                    <button
                        v-for="r in declineReasons"
                        :key="r"
                        type="button"
                        class="rounded-full border px-2 py-0.5 text-xs hover:bg-muted"
                        :class="reason === r ? 'border-primary bg-primary/10' : ''"
                        @click="reason = r"
                    >
                        {{ r }}
                    </button>
                </div>
                <Input v-model="reason" maxlength="300" placeholder="Eigene Begründung" required minlength="3" />
                <div class="flex gap-2">
                    <button
                        type="submit"
                        class="hh-button border-[var(--wood-edge)] bg-[var(--gold)] text-[var(--wood-edge)]"
                        :disabled="busy || reason.length < 3"
                    >
                        Antwort senden
                    </button>
                    <button type="button" class="hh-button border-[var(--wood-edge)] bg-background" @click="mode = 'view'">Zurück</button>
                </div>
            </form>
        </template>
    </article>
</template>
