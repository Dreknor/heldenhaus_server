<script setup lang="ts">
import HeroAvatar from '@/components/game/HeroAvatar.vue';
import { Button } from '@/components/ui/button';
import { type AvatarConfig, type Equipment, BACKGROUNDS, EYES, HAIR_COLORS, HAIR_STYLES, OUTFITS, SKINS, defaultAvatar } from '@/lib/avatar';
import { useForm } from '@inertiajs/vue3';
import { Shuffle } from 'lucide-vue-next';

const props = defineProps<{ avatar: Partial<AvatarConfig> | null; seed: number; equipment: Equipment; classIcon: string | null }>();
const emit = defineEmits<{ saved: [] }>();

const form = useForm<AvatarConfig>({ ...defaultAvatar(props.seed), ...(props.avatar ?? {}) });

function randomize() {
    const pick = <T>(list: T[]) => list[Math.floor(Math.random() * list.length)];
    Object.assign(form, {
        skin: pick(SKINS),
        hair: pick(Object.keys(HAIR_STYLES)),
        hair_color: pick(HAIR_COLORS),
        eyes: pick(Object.keys(EYES)),
        outfit: pick(OUTFITS),
        background: pick(BACKGROUNDS),
    });
}

function save() {
    form.put(route('hero.avatar'), { preserveScroll: true, onSuccess: () => emit('saved') });
}

const swatch = (active: boolean) => `size-8 rounded-full border-2 transition ${active ? 'border-foreground scale-110' : 'border-transparent hover:scale-105'}`;
const chip = (active: boolean) =>
    `rounded-lg border px-2.5 py-1 text-xs font-semibold transition ${active ? 'border-primary bg-primary text-primary-foreground' : 'hover:bg-muted'}`;
</script>

<template>
    <div class="grid gap-5 md:grid-cols-[auto_1fr]">
        <div class="flex flex-col items-center gap-3">
            <HeroAvatar :avatar="form.data()" :seed="seed" :equipment="equipment" :class-icon="classIcon" :size="180" />
            <Button variant="outline" size="sm" @click="randomize"><Shuffle class="size-4" /> Zufall</Button>
        </div>

        <div class="grid content-start gap-4">
            <div class="grid gap-1.5">
                <p class="text-sm font-semibold">Hautton</p>
                <div class="flex flex-wrap gap-2">
                    <button v-for="c in SKINS" :key="c" :class="swatch(form.skin === c)" :style="{ background: c }" :aria-label="`Hautton ${c}`" @click="form.skin = c" />
                </div>
            </div>
            <div class="grid gap-1.5">
                <p class="text-sm font-semibold">Frisur</p>
                <div class="flex flex-wrap gap-1.5">
                    <button v-for="(label, key) in HAIR_STYLES" :key="key" :class="chip(form.hair === key)" @click="form.hair = key">{{ label }}</button>
                </div>
            </div>
            <div class="grid gap-1.5">
                <p class="text-sm font-semibold">Haarfarbe</p>
                <div class="flex flex-wrap gap-2">
                    <button v-for="c in HAIR_COLORS" :key="c" :class="swatch(form.hair_color === c)" :style="{ background: c }" :aria-label="`Haarfarbe ${c}`" @click="form.hair_color = c" />
                </div>
            </div>
            <div class="grid gap-1.5">
                <p class="text-sm font-semibold">Augen</p>
                <div class="flex flex-wrap gap-1.5">
                    <button v-for="(label, key) in EYES" :key="key" :class="chip(form.eyes === key)" @click="form.eyes = key">{{ label }}</button>
                </div>
            </div>
            <div class="grid gap-1.5">
                <p class="text-sm font-semibold">Kleidung</p>
                <div class="flex flex-wrap gap-2">
                    <button v-for="c in OUTFITS" :key="c" :class="swatch(form.outfit === c)" :style="{ background: c }" :aria-label="`Kleidung ${c}`" @click="form.outfit = c" />
                </div>
            </div>
            <div class="grid gap-1.5">
                <p class="text-sm font-semibold">Hintergrund</p>
                <div class="flex flex-wrap gap-2">
                    <button v-for="c in BACKGROUNDS" :key="c" :class="swatch(form.background === c)" :style="{ background: c }" :aria-label="`Hintergrund ${c}`" @click="form.background = c" />
                </div>
            </div>
            <div><Button :disabled="form.processing" @click="save">Look speichern</Button></div>
        </div>
    </div>
</template>
