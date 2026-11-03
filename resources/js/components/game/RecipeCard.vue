<script setup lang="ts">
import ItemIcon from '@/components/game/ItemIcon.vue';
import { Check, Clock3, Coins, Lock } from 'lucide-vue-next';
import { computed } from 'vue';

/**
 * Rezeptkarte im Handwerk: großes Bild des Ergebnisses, Zutaten als Bilder mit Fortschritt
 * und eine klare Ansage, was noch fehlt – damit man ohne Lesen sieht, was gerade baubar ist.
 */
export interface RecipeItem {
    key: string;
    name: string;
    icon: string;
    type?: string;
    quantity: number;
    have: number;
}
export interface Recipe {
    key: string;
    output: RecipeItem;
    station: string;
    station_name?: string;
    station_level: number;
    level: number;
    unlock: string;
    learn: RecipeItem[] | null;
    inputs: RecipeItem[];
    gold: number;
    seconds: number;
    unlocked: boolean;
    station_ok: boolean;
    has_materials: boolean;
}

const props = defineProps<{ recipe: Recipe; slotsFull: boolean; busy: boolean; libraryAvailable: boolean; lockedLevel?: number | null }>();
const emit = defineEmits<{ craft: [recipe: Recipe]; learn: [recipe: Recipe] }>();

const STATIONS: Record<string, string> = { workbench: 'Werkbank', forge: 'Schmiede', alchemy: 'Alchemietisch', sewing: 'Nähtisch' };
const station = computed(() => props.recipe.station_name ?? STATIONS[props.recipe.station] ?? props.recipe.station);
const ready = computed(() => !props.lockedLevel && props.recipe.unlocked && props.recipe.station_ok && props.recipe.has_materials);
const missing = computed(() => props.recipe.inputs.filter((i) => i.have < i.quantity));
const duration = computed(() => {
    const s = props.recipe.seconds;
    if (!s) return 'sofort';
    if (s < 60) return `${s} Sek.`;
    if (s < 3600) return `${Math.ceil(s / 60)} Min.`;
    return `${Math.ceil(s / 3600)} Std.`;
});
const hint = computed(() => {
    const r = props.recipe;
    if (props.lockedLevel)
        return r.has_materials ? `Materialien da – Werkstatt ab Level ${props.lockedLevel}` : `Werkstatt ab Level ${props.lockedLevel}`;
    if (!r.unlocked) {
        if (r.unlock === 'library') return props.libraryAvailable ? 'In der Bibliothek lernen' : 'Braucht eine Bibliothek';
        return `Ab Handwerk-Stufe ${r.level}`;
    }
    if (!r.station_ok) return `${station.value} Stufe ${r.station_level} bauen`;
    if (missing.value.length) return `Es fehlt: ${missing.value.map((i) => `${i.quantity - i.have}× ${i.name}`).join(', ')}`;
    if (props.slotsFull) return 'Alle Werkplätze belegt';
    return '';
});
</script>

<template>
    <article
        class="hh-panel group relative flex flex-col overflow-hidden hh-parchment transition"
        :class="
            ready
                ? 'border-emerald-300 shadow-emerald-100 hover:-translate-y-0.5 hover:shadow-md dark:border-emerald-800 dark:shadow-none'
                : 'border-transparent'
        "
    >
        <span
            v-if="ready"
            class="absolute right-3 top-3 z-[1] flex items-center gap-1 rounded-full bg-emerald-500 px-2.5 py-1 text-xs font-black text-white shadow"
        >
            <Check class="size-3.5 stroke-[3]" /> Kannst du bauen!
        </span>
        <span v-else-if="!recipe.unlocked" class="absolute right-3 top-3 z-[1] rounded-full bg-slate-800/80 p-1.5 text-white" aria-label="Gesperrt">
            <Lock class="size-3.5" />
        </span>

        <div
            class="relative flex items-center justify-center py-5"
            :class="
                ready
                    ? 'bg-gradient-to-b from-emerald-100 to-emerald-50 dark:from-emerald-950/50 dark:to-transparent'
                    : 'bg-gradient-to-b from-amber-100/80 to-amber-50/40 dark:from-amber-950/40 dark:to-transparent'
            "
        >
            <span class="absolute bottom-3 h-3 w-24 rounded-full bg-black/10 blur-sm" aria-hidden="true" />
            <ItemIcon
                :item="{ key: recipe.output.key, type: recipe.output.type ?? 'furniture', icon: recipe.output.icon }"
                :size="88"
                class="relative transition group-hover:scale-105"
                :class="recipe.unlocked ? '' : 'opacity-50 grayscale'"
            />
        </div>

        <div class="flex flex-1 flex-col gap-3 p-4">
            <div>
                <h3 class="font-pixel text-lg tracking-wide leading-tight">
                    {{ recipe.output.name
                    }}<span v-if="recipe.output.quantity > 1" class="text-muted-foreground"> ×{{ recipe.output.quantity }}</span>
                </h3>
                <p class="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs font-semibold text-muted-foreground">
                    <span>{{ station }}</span>
                    <span class="flex items-center gap-0.5"><Clock3 class="size-3" /> {{ duration }}</span>
                    <span v-if="recipe.gold" class="flex items-center gap-0.5 text-amber-600"><Coins class="size-3" /> {{ recipe.gold }}</span>
                </p>
            </div>

            <ul class="grid gap-1.5">
                <li v-for="input in recipe.inputs" :key="input.key" class="flex items-center gap-2">
                    <ItemIcon :item="{ key: input.key, type: input.type ?? 'material', icon: input.icon }" :size="28" />
                    <span class="min-w-0 flex-1 truncate text-sm font-semibold">{{ input.name }}</span>
                    <span class="h-1.5 w-12 overflow-hidden rounded-full bg-muted" aria-hidden="true">
                        <span
                            class="block h-full rounded-full"
                            :class="input.have >= input.quantity ? 'bg-emerald-500' : 'bg-amber-400'"
                            :style="{ width: `${Math.min(100, (input.have / input.quantity) * 100)}%` }"
                        />
                    </span>
                    <span
                        class="w-10 text-right text-xs font-black tabular-nums"
                        :class="input.have >= input.quantity ? 'text-emerald-600' : 'text-rose-600'"
                    >
                        {{ Math.min(input.have, 99) }}/{{ input.quantity }}
                    </span>
                </li>
            </ul>

            <p v-if="hint" class="text-xs font-semibold text-muted-foreground">{{ hint }}</p>

            <div class="mt-auto">
                <button
                    v-if="recipe.unlock === 'library' && !recipe.unlocked"
                    class="w-full rounded-xl border-2 border-violet-300 px-4 py-2.5 font-bold text-violet-700 hover:bg-violet-50 disabled:opacity-50 dark:border-violet-800 dark:text-violet-300"
                    :disabled="!libraryAvailable || busy"
                    @click="emit('learn', recipe)"
                >
                    Rezept lernen
                </button>
                <button
                    v-else
                    class="w-full rounded-xl px-4 py-2.5 font-black transition disabled:cursor-not-allowed"
                    :class="
                        ready && !slotsFull
                            ? 'bg-gradient-to-b from-emerald-400 to-emerald-600 text-white shadow-[0_4px_0_#047857] active:translate-y-0.5 active:shadow-[0_2px_0_#047857]'
                            : 'bg-muted text-muted-foreground'
                    "
                    :disabled="!ready || slotsFull || busy"
                    @click="emit('craft', recipe)"
                >
                    Herstellen
                </button>
            </div>
        </div>
    </article>
</template>
