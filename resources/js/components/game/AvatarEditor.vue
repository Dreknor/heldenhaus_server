<script setup lang="ts">
import HeroAvatar from '@/components/game/HeroAvatar.vue';
import { Button } from '@/components/ui/button';
import {
    type AvatarConfig,
    type Equipment,
    BACKGROUNDS,
    BEARDS,
    BODIES,
    CLOTHES,
    EYES,
    HAIR_COLORS,
    HAIR_STYLES,
    OUTFITS,
    SKINS,
    defaultAvatar,
    normalizeAvatar,
} from '@/lib/avatar';
import { useForm } from '@inertiajs/vue3';
import { Shuffle } from 'lucide-vue-next';

const props = defineProps<{ avatar: Partial<AvatarConfig> | null; seed: number; equipment: Equipment; classKey: string | null }>();
const emit = defineEmits<{ saved: [] }>();

const form = useForm<AvatarConfig>(normalizeAvatar({ ...defaultAvatar(props.seed), ...(props.avatar ?? {}) }));

function randomize() {
    const pick = <T,>(list: T[]) => list[Math.floor(Math.random() * list.length)];
    Object.assign(form, {
        body: pick(Object.keys(BODIES)),
        skin: pick(SKINS),
        hair: pick(Object.keys(HAIR_STYLES)),
        hair_color: pick(HAIR_COLORS),
        eyes: pick(Object.keys(EYES)),
        outfit: pick(OUTFITS),
        clothes: pick(Object.keys(CLOTHES)),
        background: pick(BACKGROUNDS),
    });
}

function save() {
    form.put(route('hero.avatar'), { preserveScroll: true, onSuccess: () => emit('saved') });
}

const swatch = (active: boolean) =>
    `size-8 rounded-full border-2 transition ${active ? 'border-foreground scale-110' : 'border-transparent hover:scale-105'}`;
const chip = (active: boolean) =>
    `rounded-lg border px-2.5 py-1 text-xs font-semibold transition ${active ? 'border-primary bg-primary text-primary-foreground' : 'hover:bg-muted'}`;
const choices = [
    { label: 'Körperform', field: 'body', options: BODIES, fallback: 'male' },
    { label: 'Frisur', field: 'hair', options: HAIR_STYLES, fallback: 'plain' },
    { label: 'Augen', field: 'eyes', options: EYES, fallback: 'round' },
    { label: 'Bart', field: 'beard', options: BEARDS, fallback: 'none' },
    { label: 'Kleidung', field: 'clothes', options: CLOTHES, fallback: 'tShirt' },
] as const;
const colors = [
    { label: 'Hautton', field: 'skin', options: SKINS },
    { label: 'Haarfarbe', field: 'hair_color', options: HAIR_COLORS },
    { label: 'Kleidungsfarbe', field: 'outfit', options: OUTFITS },
    { label: 'Hintergrund', field: 'background', options: BACKGROUNDS },
] as const;
</script>

<template>
    <div class="grid gap-5 md:grid-cols-[auto_1fr]">
        <div class="flex flex-col items-center gap-3">
            <HeroAvatar :avatar="form.data()" :seed="seed" :equipment="equipment" :class-key="classKey" :size="200" />
            <p class="max-w-52 text-center text-xs text-muted-foreground">Klasse und angelegte Ausrüstung siehst du direkt an deinem Helden.</p>
            <Button variant="outline" size="sm" @click="randomize"><Shuffle class="size-4" /> Zufall</Button>
        </div>

        <div class="grid content-start gap-4">
            <div v-for="c in choices" :key="c.field" class="grid gap-1.5">
                <p class="text-sm font-semibold">{{ c.label }}</p>
                <div class="flex flex-wrap gap-1.5">
                    <button
                        v-for="(label, key) in c.options"
                        :key="key"
                        :class="chip((form[c.field] ?? c.fallback) === key)"
                        @click="form[c.field] = key"
                    >
                        {{ label }}
                    </button>
                </div>
            </div>
            <div v-for="c in colors" :key="c.field" class="grid gap-1.5">
                <p class="text-sm font-semibold">{{ c.label }}</p>
                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="hex in c.options"
                        :key="hex"
                        :class="swatch(form[c.field] === hex)"
                        :style="{ background: hex }"
                        :aria-label="`${c.label} ${hex}`"
                        @click="form[c.field] = hex"
                    />
                </div>
            </div>
            <div><Button :disabled="form.processing" @click="save">Look speichern</Button></div>
        </div>
    </div>
</template>
