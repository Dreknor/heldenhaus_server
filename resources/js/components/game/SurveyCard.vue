<script setup lang="ts">
import Emoji from '@/components/game/Emoji.vue';
import GamePanel from '@/components/game/GamePanel.vue';
import { router } from '@inertiajs/vue3';
import { MessageCircleHeart } from 'lucide-vue-next';
import { computed, reactive, ref } from 'vue';

export interface DueSurvey {
    kind: 'baseline' | 'mood';
    title: string;
    intro: string;
    questions?: { key: string; text: string; low: string; high: string }[];
    smileys?: { value: number; icon: string }[];
    reasons?: { key: string; label: string }[];
}

const props = defineProps<{ survey: DueSurvey }>();

const answers = reactive<Record<string, number>>({});
const mood = ref<number | null>(null);
const reasons = ref<string[]>([]);
const sending = ref(false);

const complete = computed(() =>
    props.survey.kind === 'baseline' ? (props.survey.questions ?? []).every((q) => answers[q.key]) : mood.value !== null,
);

function send(skip = false) {
    sending.value = true;
    const payload = props.survey.kind === 'baseline' ? { ...answers } : { mood: mood.value, reasons: reasons.value };
    router.post(
        route('surveys.store'),
        { kind: props.survey.kind, skip, answers: skip ? null : payload },
        { preserveScroll: true, onFinish: () => (sending.value = false) },
    );
}

function toggle(key: string) {
    reasons.value = reasons.value.includes(key) ? reasons.value.filter((r) => r !== key) : [...reasons.value, key];
}
</script>

<template>
    <GamePanel :title="survey.title" :subtitle="survey.intro" :icon="MessageCircleHeart">
        <template v-if="survey.kind === 'baseline'">
            <fieldset v-for="q in survey.questions" :key="q.key" class="grid gap-1.5">
                <legend class="text-sm font-bold">{{ q.text }}</legend>
                <div class="flex items-center gap-1.5 text-xs">
                    <span class="w-16 text-right text-muted-foreground">{{ q.low }}</span>
                    <button
                        v-for="v in 5"
                        :key="v"
                        type="button"
                        class="size-9 rounded-md border-2 font-bold transition"
                        :class="answers[q.key] === v ? 'border-amber-600 bg-amber-400 text-white' : 'border-[var(--parchment-edge)] bg-background/60'"
                        :aria-pressed="answers[q.key] === v"
                        @click="answers[q.key] = v"
                    >
                        {{ v }}
                    </button>
                    <span class="w-16 text-muted-foreground">{{ q.high }}</span>
                </div>
            </fieldset>
        </template>

        <template v-else>
            <div class="flex gap-2" role="radiogroup" aria-label="Wie viel Spaß macht dir Heldenhaus?">
                <button
                    v-for="s in survey.smileys"
                    :key="s.value"
                    type="button"
                    role="radio"
                    :aria-checked="mood === s.value"
                    class="rounded-xl border-2 p-1.5 text-3xl transition"
                    :class="
                        mood === s.value
                            ? 'scale-110 border-amber-500 bg-amber-100 dark:bg-amber-900/40'
                            : 'border-transparent opacity-80 hover:opacity-100'
                    "
                    @click="mood = s.value"
                >
                    <Emoji :glyph="s.icon" />
                </button>
            </div>
            <div v-if="mood !== null && mood <= 3" class="grid gap-1.5">
                <p class="text-sm font-bold">Was soll besser werden? (freiwillig)</p>
                <div class="flex flex-wrap gap-1.5">
                    <button
                        v-for="r in survey.reasons"
                        :key="r.key"
                        type="button"
                        class="rounded-full border-2 px-3 py-1 text-xs font-bold"
                        :class="
                            reasons.includes(r.key) ? 'border-amber-600 bg-amber-400 text-white' : 'border-[var(--parchment-edge)] bg-background/60'
                        "
                        :aria-pressed="reasons.includes(r.key)"
                        @click="toggle(r.key)"
                    >
                        {{ r.label }}
                    </button>
                </div>
            </div>
        </template>

        <div class="flex flex-wrap gap-2">
            <button
                class="hh-button border-[#2c4a14] bg-primary text-primary-foreground hover:brightness-110 disabled:opacity-50"
                :disabled="!complete || sending"
                @click="send()"
            >
                Abschicken
            </button>
            <button class="text-xs font-bold text-muted-foreground hover:text-foreground" :disabled="sending" @click="send(true)">Heute nicht</button>
        </div>
    </GamePanel>
</template>
