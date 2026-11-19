<script setup lang="ts">
import ProposalCard, { type ProposalView } from '@/components/council/ProposalCard.vue';
import Emoji from '@/components/game/Emoji.vue';
import GamePanel from '@/components/game/GamePanel.vue';
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { Inbox, Lightbulb } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Template {
    key: string;
    title: string;
    icon: string;
    stars: number | null;
}

const props = defineProps<{
    proposals: ProposalView[];
    templates: { reward: Template[]; pool: Template[]; parent: Template[] };
    freeText: boolean;
    parents: { id: number; name: string }[];
    declineReasons: string[];
    isParent: boolean;
    canPropose: boolean;
}>();

type Kind = 'reward' | 'pool' | 'parent';
const kind = ref<Kind>('reward');
const kinds: { key: Kind; label: string; icon: string }[] = [
    { key: 'reward', label: 'Belohnung', icon: '🎁' },
    { key: 'pool', label: 'Aufgabe für die Börse', icon: '📋' },
    { key: 'parent', label: 'Aufgabe für Eltern', icon: '🧑‍🔧' },
];

const form = useForm({
    kind: 'reward' as 'reward' | 'task',
    target: null as 'pool' | 'parent' | null,
    for_user_id: props.parents[0]?.id ?? null,
    template_key: null as string | null,
    title: '',
    stars: undefined as number | undefined,
});

const list = computed(() => props.templates[kind.value]);
const open = computed(() => props.proposals.filter((p) => p.status === 'open'));
const done = computed(() => props.proposals.filter((p) => p.status !== 'open'));

function pick(t: Template) {
    form.template_key = t.key;
    form.title = '';
    form.stars = t.stars ?? undefined;
}

function submit() {
    form.kind = kind.value === 'reward' ? 'reward' : 'task';
    form.target = kind.value === 'reward' ? null : kind.value;
    form.post(route('proposals.store'), {
        preserveScroll: true,
        onSuccess: () => form.reset('template_key', 'title', 'stars'),
    });
}

const errorMessage = computed(() => (form.errors as Record<string, string | undefined>).game ?? form.errors.title ?? form.errors.stars);
const canSend = computed(() => (form.template_key || (props.freeText && form.title.trim().length > 1)) && !form.processing);
</script>

<template>
    <Head title="Vorschläge" />

    <AppLayout :breadcrumbs="[{ title: 'Vorschläge', href: '/proposals' }]">
        <div class="flex flex-1 flex-col gap-5 p-4 md:p-6">
            <h1 class="text-2xl font-extrabold tracking-tight">Vorschläge</h1>

            <GamePanel v-if="canPropose" title="Ich hab eine Idee!" subtitle="Deine Eltern entscheiden und antworten dir." :icon="Lightbulb">
                <div class="flex flex-wrap gap-2" role="radiogroup" aria-label="Art des Vorschlags">
                    <button
                        v-for="k in kinds"
                        :key="k.key"
                        role="radio"
                        :aria-checked="kind === k.key"
                        class="hh-button border-[var(--wood-edge)]"
                        :class="kind === k.key ? 'bg-[var(--gold)] text-[var(--wood-edge)]' : 'bg-background'"
                        :disabled="k.key === 'parent' && !parents.length"
                        @click="
                            kind = k.key;
                            form.template_key = null;
                        "
                    >
                        <Emoji :glyph="k.icon" /> {{ k.label }}
                    </button>
                </div>

                <label v-if="kind === 'parent'" class="grid gap-1 text-sm font-semibold">
                    Für wen?
                    <select v-model="form.for_user_id" class="h-9 rounded-md border border-input bg-background px-3">
                        <option v-for="p in parents" :key="p.id" :value="p.id">{{ p.name }}</option>
                    </select>
                </label>

                <div class="grid max-h-72 gap-2 overflow-y-auto sm:grid-cols-2 lg:grid-cols-3">
                    <button
                        v-for="t in list"
                        :key="t.key"
                        type="button"
                        class="flex items-center gap-2 rounded-md border-2 p-2 text-left text-sm font-bold transition"
                        :class="
                            form.template_key === t.key
                                ? 'border-primary bg-primary/10'
                                : 'border-[var(--parchment-edge)] bg-background/60 hover:border-primary'
                        "
                        @click="pick(t)"
                    >
                        <span class="text-xl"><Emoji :glyph="t.icon" /></span>
                        <span class="flex-1">{{ t.title }}</span>
                        <span v-if="t.stars" class="text-xs text-amber-700 dark:text-amber-400">⭐ {{ t.stars }}</span>
                    </button>
                </div>

                <div v-if="freeText" class="grid gap-1">
                    <label for="p-title" class="text-sm font-semibold">… oder schreib selbst:</label>
                    <Input id="p-title" v-model="form.title" maxlength="120" placeholder="Meine Idee" @input="form.template_key = null" />
                </div>
                <p v-else class="text-xs text-muted-foreground">Eigene Texte können deine Eltern in den Familieneinstellungen freischalten.</p>

                <label v-if="kind !== 'parent'" class="grid max-w-48 gap-1 text-sm font-semibold">
                    {{ kind === 'reward' ? 'Wunschpreis (Sterne)' : 'Sterne-Vorschlag' }}
                    <Input v-model.number="form.stars" type="number" min="1" max="1000" />
                </label>
                <InputError :message="errorMessage" />
                <div>
                    <button class="hh-button border-[#2c4a14] bg-primary text-primary-foreground" :disabled="!canSend" @click="submit">
                        Vorschlag senden
                    </button>
                </div>
            </GamePanel>

            <GamePanel :title="isParent ? 'Warten auf euch' : 'Meine offenen Vorschläge'" :icon="Inbox">
                <div class="grid gap-2 md:grid-cols-2">
                    <ProposalCard v-for="p in open" :key="p.id" :proposal="p" :can-decide="isParent" :decline-reasons="declineReasons" />
                </div>
                <p v-if="!open.length" class="text-sm text-muted-foreground">
                    {{ isParent ? 'Gerade wartet kein Vorschlag.' : 'Noch nichts offen – hast du eine Idee?' }}
                </p>
            </GamePanel>

            <section v-if="done.length" class="grid gap-2">
                <h2 class="font-extrabold">Schon entschieden</h2>
                <div class="grid gap-2 md:grid-cols-2">
                    <ProposalCard v-for="p in done" :key="p.id" :proposal="p" :can-decide="false" :decline-reasons="[]" />
                </div>
            </section>
        </div>
    </AppLayout>
</template>
