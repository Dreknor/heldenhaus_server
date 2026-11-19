<script setup lang="ts">
import Emoji from '@/components/game/Emoji.vue';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import type { Member } from '@/types';
import { router } from '@inertiajs/vue3';
import { Star } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

interface Template {
    key: string;
    area: string;
    area_label: string;
    title: string;
    type: 'habit' | 'daily' | 'todo';
    stars: number;
    icon: string;
    anchor: string | null;
}

const props = defineProps<{ members: Member[] }>();
const open = defineModel<boolean>('open', { default: false });

const children = computed(() => props.members.filter((m) => m.role === 'child'));
const target = ref<number | 'pool'>('pool');
const area = ref<string>('');
const templates = ref<Template[]>([]);
const areas = ref<Record<string, string>>({});
const loading = ref(false);
const error = ref('');
const selected = ref<Template | null>(null);
const stars = ref(0);

async function load() {
    loading.value = true;
    error.value = '';
    try {
        const params: Record<string, string | number> = {};
        if (target.value !== 'pool') params.member = target.value;
        if (area.value) params.area = area.value;
        const response = await fetch(route('task-templates.index', params), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (!response.ok) throw new Error();
        const data = await response.json();
        templates.value = data.templates;
        areas.value = data.areas;
    } catch {
        error.value = 'Vorlagen konnten nicht geladen werden.';
    } finally {
        loading.value = false;
    }
}

watch([open, target, area], () => open.value && load(), { immediate: true });

function choose(t: Template) {
    selected.value = t;
    stars.value = t.stars || (target.value === 'pool' ? 2 : 0);
}

function adopt() {
    if (!selected.value) return;
    router.post(
        route('task-templates.adopt', { key: selected.value.key }),
        {
            assignment_mode: target.value === 'pool' ? 'pool' : 'assigned',
            assignees: target.value === 'pool' ? [] : [target.value],
            star_reward: stars.value,
        },
        { preserveScroll: true, onSuccess: () => ((selected.value = null), (open.value = false)) },
    );
}

const typeLabel = { habit: 'Gewohnheit', daily: 'täglich', todo: 'einmalig' } as const;
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle>Aufgabe aus Vorlage</DialogTitle>
            </DialogHeader>
            <div class="grid gap-3">
                <div class="flex flex-wrap gap-2 text-sm">
                    <select v-model="target" class="h-9 rounded-md border border-input bg-background px-2" aria-label="Für wen?">
                        <option value="pool">Aufgabenbörse (alle Altersstufen)</option>
                        <option v-for="c in children" :key="c.id" :value="c.id">Für {{ c.name }} (passend zum Alter)</option>
                    </select>
                    <select v-model="area" class="h-9 rounded-md border border-input bg-background px-2" aria-label="Bereich">
                        <option value="">Alle Bereiche</option>
                        <option v-for="(label, key) in areas" :key="key" :value="key">{{ label }}</option>
                    </select>
                </div>
                <p class="text-xs text-muted-foreground">Tipp: Höchstens eine neue Aufgabe pro Woche und Kind – weniger ist mehr.</p>
                <p v-if="error" class="text-sm text-destructive">{{ error }}</p>
                <p v-if="loading" class="text-sm text-muted-foreground">Lade Vorlagen …</p>
                <div v-else class="grid max-h-80 gap-2 overflow-y-auto sm:grid-cols-2">
                    <button
                        v-for="t in templates"
                        :key="t.key"
                        type="button"
                        class="flex items-center gap-2 rounded-md border-2 p-2 text-left text-sm"
                        :class="selected?.key === t.key ? 'border-primary bg-primary/10' : 'border-muted hover:border-primary'"
                        @click="choose(t)"
                    >
                        <span class="text-2xl"><Emoji :glyph="t.icon" /></span>
                        <span class="min-w-0 flex-1">
                            <span class="block font-bold">{{ t.title }}</span>
                            <span class="block text-xs text-muted-foreground">{{ t.area_label }} · {{ typeLabel[t.type] }}</span>
                        </span>
                        <span v-if="t.stars" class="flex items-center gap-0.5 text-xs font-bold text-amber-700"
                            ><Star class="size-3 fill-current" />{{ t.stars }}</span
                        >
                    </button>
                </div>
                <div v-if="selected" class="flex flex-wrap items-end gap-3 border-t pt-3">
                    <label class="grid gap-1 text-sm font-semibold">
                        Sterne
                        <Input v-model.number="stars" type="number" min="0" max="1000" class="w-24" />
                    </label>
                    <button class="hh-button border-[#2c4a14] bg-primary text-primary-foreground" @click="adopt">
                        „{{ selected.title }}“ anlegen
                    </button>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
