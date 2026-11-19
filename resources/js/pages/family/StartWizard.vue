<script setup lang="ts">
import Emoji from '@/components/game/Emoji.vue';
import GamePanel from '@/components/game/GamePanel.vue';
import InputError from '@/components/InputError.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Sparkles, Star } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Template {
    key: string;
    area: string;
    area_label: string;
    title: string;
    type: 'habit' | 'daily' | 'todo';
    stars: number;
    icon: string;
    steps: string[];
    anchor: string | null;
}

const props = defineProps<{
    member: { id: number; name: string; age_band: string | null; done: boolean };
    templates: Template[];
    areas: Record<string, string>;
    min: number;
    max: number;
}>();

const area = ref<string | null>(null);
const form = useForm({ templates: [] as string[] });
const shown = computed(() => props.templates.filter((t) => !area.value || t.area === area.value));
const typeLabel = { habit: 'Gewohnheit', daily: 'täglich', todo: 'einmalig' } as const;

function toggle(key: string) {
    const i = form.templates.indexOf(key);
    if (i >= 0) form.templates.splice(i, 1);
    else if (form.templates.length < props.max) form.templates.push(key);
}

const submit = () => form.post(route('family.starter-tasks', { member: props.member.id }));
</script>

<template>
    <Head title="Startassistent" />

    <AppLayout
        :breadcrumbs="[
            { title: 'Familieneinstellungen', href: '/family/settings' },
            { title: 'Startassistent', href: '#' },
        ]"
    >
        <div class="flex flex-1 flex-col gap-5 p-4 md:p-6">
            <h1 class="text-2xl font-extrabold tracking-tight">Die ersten Aufgaben für {{ member.name }}</h1>

            <GamePanel v-if="member.done" title="Schon erledigt">
                <p class="text-sm">
                    Für {{ member.name }} wurden die ersten Aufgaben schon gewählt. Neue Aufgaben legt ihr unter Familienaufgaben an – höchstens eine
                    pro Woche.
                </p>
                <Link :href="route('family.tasks')" class="hh-button w-fit border-[var(--wood-edge)] bg-[var(--gold)] text-[var(--wood-edge)]"
                    >Zu den Familienaufgaben</Link
                >
            </GamePanel>

            <template v-else>
                <GamePanel tone="sky" title="Weniger ist mehr" :icon="Sparkles">
                    <p class="text-sm font-semibold">
                        Wählt {{ min }} bis {{ max }} Aufgaben, die {{ member.name }} gut schaffen kann. Wenige Aufgaben, die klappen, motivieren mehr
                        als eine lange Liste. Danach kommt höchstens eine neue Aufgabe pro Woche dazu.
                    </p>
                </GamePanel>

                <div class="flex flex-wrap gap-1.5">
                    <button
                        class="rounded-full border px-3 py-1 text-sm font-bold"
                        :class="!area ? 'border-primary bg-primary/10' : ''"
                        @click="area = null"
                    >
                        Alle
                    </button>
                    <button
                        v-for="(label, key) in areas"
                        :key="key"
                        class="rounded-full border px-3 py-1 text-sm font-bold"
                        :class="area === key ? 'border-primary bg-primary/10' : ''"
                        @click="area = key"
                    >
                        {{ label }}
                    </button>
                </div>

                <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                    <button
                        v-for="t in shown"
                        :key="t.key"
                        type="button"
                        class="flex items-start gap-3 rounded-md border-2 bg-card p-3 text-left transition"
                        :class="
                            form.templates.includes(t.key) ? 'border-primary bg-primary/10' : 'border-[var(--parchment-edge)] hover:border-primary'
                        "
                        :aria-pressed="form.templates.includes(t.key)"
                        :disabled="!form.templates.includes(t.key) && form.templates.length >= max"
                        @click="toggle(t.key)"
                    >
                        <span class="text-3xl"><Emoji :glyph="t.icon" /></span>
                        <span class="min-w-0 flex-1">
                            <span class="block font-extrabold leading-tight">{{ t.title }}</span>
                            <span class="block text-xs text-muted-foreground">
                                {{ t.area_label }} · {{ typeLabel[t.type] }}<template v-if="t.anchor"> · {{ t.anchor }}</template>
                            </span>
                            <span v-if="t.stars" class="mt-0.5 flex items-center gap-1 text-xs font-bold text-amber-700 dark:text-amber-400">
                                <Star class="size-3 fill-current" /> {{ t.stars }} Sterne (Familienaufgabe mit Freigabe)
                            </span>
                        </span>
                    </button>
                </div>

                <div class="sticky bottom-3 flex flex-wrap items-center gap-3 rounded-md border-2 border-[var(--wood-edge)] bg-card p-3 shadow-lg">
                    <span class="flex-1 text-sm font-bold">{{ form.templates.length }} von {{ min }}–{{ max }} gewählt</span>
                    <InputError :message="form.errors.templates" />
                    <Link :href="route('family.settings')" class="text-sm text-muted-foreground underline">Später</Link>
                    <button
                        class="hh-button border-[#2c4a14] bg-primary text-primary-foreground"
                        :disabled="form.processing || form.templates.length < min"
                        @click="submit"
                    >
                        Aufgaben anlegen
                    </button>
                </div>
            </template>
        </div>
    </AppLayout>
</template>
