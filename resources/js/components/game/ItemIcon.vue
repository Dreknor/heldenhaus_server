<script setup lang="ts">
import { RARITY_COLORS, shade } from '@/lib/avatar';
import { emojiUrl } from '@/lib/emoji';
import { computed, ref } from 'vue';

/**
 * Item-Grafiken (Konzept §2.9): das Icon-Emoji des Items als 3D-Bild (Fluent Emoji), Eier zusätzlich mit dem
 * Tier, das daraus schlüpft. Gibt es kein Bild (z. B. im Admin mit neuem Emoji angelegt), zeichnen wir das
 * Motiv des Typs selbst, eingefärbt nach Seltenheit.
 */
export interface ItemLike {
    key?: string | null;
    type: string;
    slot?: string | null;
    rarity?: string | null;
    icon?: string | null;
}

const props = withDefaults(defineProps<{ item: ItemLike; size?: number; framed?: boolean }>(), { size: 48, framed: false });

const ink = '#1f2937';
const rarity = computed(() => RARITY_COLORS[props.item.rarity ?? 'common'] ?? RARITY_COLORS.common);
const key = computed(() => props.item.key ?? '');
const uid = Math.random().toString(36).slice(2, 8);

// Eier: das Ei vorn, das Tier daraus klein daneben (Icon-Emoji der Eier ist das Tier)
const art = computed(() => (key.value.startsWith('egg_') ? emojiUrl('🥚') : emojiUrl(props.item.icon)));
const petArt = computed(() => (key.value.startsWith('egg_') ? `/images/pets/${key.value.slice(4)}.webp` : null));
const petMissing = ref(false);

/** Motiv: zuerst nach Schlüssel, sonst nach Typ/Slot. */
const motif = computed(() => {
    const k = key.value;
    if (k.startsWith('egg_')) return 'egg';
    if (k.startsWith('potion_') && props.item.type === 'hatching_potion') return 'hatch';
    const known = [
        'food_apple',
        'food_cake',
        'wood',
        'stone',
        'ink',
        'crystal_shard',
        'herbs',
        'feather',
        'paint',
        'stardust',
        'potion_health',
        'potion_mana',
        'head_cap',
        'weapon_wooden_sword',
        'shield_lid',
        'armor_hoodie',
        'accessory_glasses',
    ];
    if (known.includes(k)) return k;
    if (props.item.type === 'equipment') return `slot_${props.item.slot ?? 'armor'}`;
    return `type_${props.item.type}`;
});

const EGG_COLORS: Record<string, [string, string]> = {
    egg_wolf: ['#cbd5e1', '#64748b'],
    egg_cat: ['#fed7aa', '#f97316'],
    egg_owl: ['#e7d3b0', '#92400e'],
    egg_dragon: ['#bbf7d0', '#dc2626'],
};
const egg = computed(() => EGG_COLORS[key.value] ?? ['#fef3c7', rarity.value]);

const HATCH: Record<string, string> = { potion_fire: '#f97316', potion_ice: '#38bdf8', potion_rainbow: `url(#rb-${uid})` };
const liquid = computed(() => HATCH[key.value] ?? rarity.value);
</script>

<template>
    <span class="relative inline-flex shrink-0 items-center justify-center" :style="{ width: `${size}px`, height: `${size}px` }">
        <span v-if="framed" class="absolute inset-0 rounded-xl opacity-20" :style="{ background: rarity }" />
        <template v-if="art">
            <img :src="art" alt="" class="relative h-[86%] w-[86%] object-contain drop-shadow-sm" draggable="false" />
            <img
                v-if="petArt && !petMissing"
                :src="petArt"
                alt=""
                class="absolute bottom-0 right-0 h-1/2 w-1/2 object-contain drop-shadow"
                draggable="false"
                @error="petMissing = true"
            />
        </template>
        <svg
            v-else
            viewBox="0 0 64 64"
            :width="size"
            :height="size"
            xmlns="http://www.w3.org/2000/svg"
            stroke-linejoin="round"
            stroke-linecap="round"
            class="relative"
            aria-hidden="true"
        >
            <defs>
                <linearGradient :id="`rb-${uid}`" x1="0" x2="1">
                    <stop offset="0" stop-color="#f87171" />
                    <stop offset=".35" stop-color="#facc15" />
                    <stop offset=".65" stop-color="#4ade80" />
                    <stop offset="1" stop-color="#818cf8" />
                </linearGradient>
            </defs>
            <ellipse cx="32" cy="58" rx="16" ry="3" fill="#000" opacity=".12" />

            <g :stroke="ink" stroke-width="3">
                <!-- Eier -->
                <template v-if="motif === 'egg'">
                    <path d="M32 6 Q50 10 50 36 Q50 54 32 54 Q14 54 14 36 Q14 10 32 6 Z" :fill="egg[0]" />
                    <circle cx="25" cy="28" r="4" :fill="egg[1]" stroke-width="0" />
                    <circle cx="38" cy="38" r="5" :fill="egg[1]" stroke-width="0" />
                    <circle cx="30" cy="45" r="2.5" :fill="egg[1]" stroke-width="0" />
                    <path d="M22 18 Q26 12 31 12" fill="none" stroke="#fff" stroke-width="2.5" opacity=".7" />
                </template>

                <!-- Schlüpfpulver: Säckchen mit Glitzer -->
                <template v-else-if="motif === 'hatch'">
                    <path d="M18 24 Q14 54 32 54 Q50 54 46 24 Z" :fill="liquid" />
                    <path d="M20 24 Q32 16 44 24 L42 14 Q32 18 22 14 Z" :fill="shade('#a16207', 0.2)" />
                    <path d="M22 14 h20" stroke-width="3.5" />
                    <path d="M26 36 l2 4 l4 2 l-4 2 l-2 4 l-2 -4 l-4 -2 l4 -2 Z" fill="#fff" stroke-width="1.2" />
                </template>

                <template v-else-if="motif === 'food_apple'">
                    <path d="M32 18 Q20 10 12 22 Q6 38 18 52 Q26 58 32 54 Q38 58 46 52 Q58 38 52 22 Q44 10 32 18 Z" fill="#ef4444" />
                    <path d="M32 18 Q32 10 36 6" fill="none" stroke="#78350f" />
                    <path d="M34 12 Q44 4 48 12 Q40 16 34 12 Z" fill="#22c55e" stroke-width="2.5" />
                    <path d="M20 26 Q22 22 26 22" fill="none" stroke="#fff" stroke-width="2.5" opacity=".7" />
                </template>
                <template v-else-if="motif === 'food_cake'">
                    <path d="M10 34 L32 20 L54 34 L54 50 L10 50 Z" fill="#fde68a" />
                    <path d="M10 34 L54 34 L32 20 Z" fill="#f9a8d4" />
                    <path d="M10 42 h44" stroke="#f472b6" />
                    <circle cx="32" cy="16" r="4" fill="#ef4444" stroke-width="2.5" />
                </template>

                <template v-else-if="motif === 'wood'">
                    <rect x="8" y="22" width="44" height="22" rx="11" fill="#b45309" />
                    <ellipse cx="50" cy="33" rx="7" ry="11" fill="#fcd34d" />
                    <ellipse cx="50" cy="33" rx="3" ry="5" fill="none" stroke="#b45309" stroke-width="2" />
                    <path d="M16 28 h20 M14 38 h24" stroke="#78350f" stroke-width="2" />
                </template>
                <template v-else-if="motif === 'stone'">
                    <path d="M10 46 L14 24 L30 14 L48 20 L56 40 L44 52 L20 52 Z" fill="#a8a29e" />
                    <path d="M22 26 L30 22 M38 40 L46 36" stroke="#e7e5e4" stroke-width="2.5" />
                </template>
                <template v-else-if="motif === 'ink'">
                    <path d="M18 26 h28 v24 q0 4 -4 4 h-20 q-4 0 -4 -4 Z" fill="#1e3a8a" />
                    <rect x="24" y="18" width="16" height="8" fill="#334155" />
                    <path d="M40 6 L54 2 L36 26 Z" fill="#f8fafc" stroke-width="2.5" />
                </template>
                <template v-else-if="motif === 'crystal_shard'">
                    <path d="M32 4 L46 22 L40 56 L24 56 L18 22 Z" fill="#67e8f9" />
                    <path d="M32 4 L32 56 M18 22 L46 22" stroke="#0e7490" stroke-width="2" />
                </template>
                <template v-else-if="motif === 'herbs'">
                    <path d="M32 56 V20" fill="none" stroke="#166534" />
                    <path
                        d="M32 40 Q14 40 12 24 Q28 22 32 38 Z M32 32 Q50 32 52 16 Q36 14 32 30 Z M32 22 Q24 12 30 4 Q38 12 32 22 Z"
                        fill="#22c55e"
                        stroke-width="2.5"
                    />
                </template>
                <template v-else-if="motif === 'feather'">
                    <path d="M48 6 Q20 14 16 44 L22 48 Q46 40 48 6 Z" fill="#e0f2fe" />
                    <path d="M46 10 L12 56" fill="none" stroke="#475569" stroke-width="2.5" />
                </template>
                <template v-else-if="motif === 'paint'">
                    <path d="M32 8 Q56 8 56 30 Q56 40 46 38 Q38 36 40 46 Q42 56 30 56 Q8 54 8 32 Q8 8 32 8 Z" fill="#fef3c7" />
                    <circle cx="22" cy="24" r="4.5" fill="#ef4444" stroke-width="2" />
                    <circle cx="34" cy="18" r="4.5" fill="#3b82f6" stroke-width="2" />
                    <circle cx="46" cy="24" r="4.5" fill="#22c55e" stroke-width="2" />
                    <circle cx="20" cy="38" r="4.5" fill="#facc15" stroke-width="2" />
                </template>
                <template v-else-if="motif === 'stardust'">
                    <path d="M32 6 l7 15 l16 2 l-12 11 l3 16 l-14 -8 l-14 8 l3 -16 l-12 -11 l16 -2 Z" fill="#fde047" />
                    <circle cx="12" cy="12" r="2.5" fill="#fde047" stroke-width="1.5" />
                    <circle cx="54" cy="52" r="2.5" fill="#fde047" stroke-width="1.5" />
                </template>

                <template v-else-if="motif === 'potion_health' || motif === 'potion_mana' || motif === 'type_potion'">
                    <path d="M26 8 h12 v12 Q52 26 50 42 Q48 56 32 56 Q16 56 14 42 Q12 26 26 20 Z" fill="#f1f5f9" />
                    <path
                        d="M15 38 Q32 32 49 38 Q48 54 32 54 Q16 54 15 38 Z"
                        :fill="motif === 'potion_health' ? '#ef4444' : motif === 'potion_mana' ? '#3b82f6' : rarity"
                        stroke-width="0"
                    />
                    <path d="M26 8 h12 v12 Q52 26 50 42 Q48 56 32 56 Q16 56 14 42 Q12 26 26 20 Z" fill="none" />
                    <rect x="24" y="4" width="16" height="6" rx="2" fill="#92400e" />
                    <path d="M20 34 Q20 28 24 26" fill="none" stroke="#fff" stroke-width="2.5" opacity=".8" />
                </template>

                <!-- Ausrüstung -->
                <template v-else-if="motif === 'head_cap'">
                    <path d="M10 38 Q10 12 32 12 Q54 12 54 38 Z" fill="#ef4444" />
                    <path d="M34 38 Q56 36 62 44 Q48 48 34 44 Z" fill="#dc2626" stroke-width="2.5" />
                    <circle cx="32" cy="12" r="3" fill="#fff" stroke-width="2" />
                </template>
                <template v-else-if="motif === 'weapon_wooden_sword'">
                    <path d="M40 6 L52 12 L24 44 L18 40 Z" fill="#d6a46c" />
                    <path d="M12 36 L28 50" stroke-width="5" />
                    <path d="M12 36 L28 50" stroke="#8b5a2b" stroke-width="2.5" />
                    <path d="M16 46 L8 56" stroke="#78350f" stroke-width="5" />
                </template>
                <template v-else-if="motif === 'shield_lid'">
                    <circle cx="32" cy="32" r="24" fill="#cbd5e1" />
                    <circle cx="32" cy="32" r="15" fill="none" stroke="#94a3b8" stroke-width="2.5" />
                    <circle cx="32" cy="32" r="5" fill="#64748b" stroke-width="2.5" />
                </template>
                <template v-else-if="motif === 'armor_hoodie'">
                    <path d="M16 18 Q24 10 32 14 Q40 10 48 18 L58 30 L50 36 L48 30 V56 H16 V30 L14 36 L6 30 Z" fill="#7c3aed" />
                    <path d="M24 42 h16 v8 h-16 Z" fill="#6d28d9" stroke-width="2" />
                    <path d="M28 14 v12 M36 14 v12" stroke="#f5f3ff" stroke-width="2" />
                </template>
                <template v-else-if="motif === 'accessory_glasses'">
                    <circle cx="19" cy="34" r="11" fill="#bfdbfe" fill-opacity=".5" />
                    <circle cx="45" cy="34" r="11" fill="#bfdbfe" fill-opacity=".5" />
                    <path d="M30 32 Q32 28 34 32 M8 32 L4 26 M56 32 L60 26" fill="none" />
                </template>
                <template v-else-if="motif === 'slot_head'">
                    <path d="M10 40 Q10 10 32 10 Q54 10 54 40 L46 38 Q32 32 18 38 Z" :fill="rarity" />
                    <path d="M32 10 v-6" />
                    <circle cx="32" cy="4" r="3" fill="#fbbf24" stroke-width="2" />
                </template>
                <template v-else-if="motif === 'slot_weapon'">
                    <path d="M42 4 L54 10 L24 44 L18 40 Z" :fill="rarity" />
                    <path d="M12 36 L28 50" stroke-width="5" />
                    <path d="M12 36 L28 50" stroke="#fbbf24" stroke-width="2.5" />
                    <path d="M16 46 L8 56" stroke="#78350f" stroke-width="5" />
                </template>
                <template v-else-if="motif === 'slot_shield'">
                    <path d="M12 10 h40 v18 Q52 48 32 58 Q12 48 12 28 Z" :fill="rarity" />
                    <path d="M32 14 v40 M16 28 h32" stroke="#fff" stroke-width="2.5" opacity=".75" />
                </template>
                <template v-else-if="motif === 'slot_accessory'">
                    <path d="M18 8 Q32 30 46 8" fill="none" stroke="#fbbf24" stroke-width="2.5" />
                    <path d="M32 26 L44 40 L32 56 L20 40 Z" :fill="rarity" />
                </template>
                <template v-else-if="motif.startsWith('slot_')">
                    <path d="M16 14 Q32 6 48 14 L52 52 H12 Z" :fill="rarity" />
                    <circle v-for="x in [24, 32, 40]" :key="x" :cx="x" cy="24" r="2" fill="#fff" stroke-width="0" />
                </template>

                <!-- Rückfall nach Typ (z. B. neue Items aus dem Admin-Bereich) -->
                <template v-else-if="motif === 'type_food'">
                    <circle cx="32" cy="34" r="20" :fill="rarity" />
                    <path d="M32 14 Q34 6 40 6" fill="none" stroke="#78350f" />
                </template>
                <template v-else-if="motif === 'type_furniture'">
                    <path d="M12 30 h40 v20 h-40 Z M16 30 V14 h32 v16" :fill="shade(rarity, -0.1)" />
                    <path d="M16 50 v6 M48 50 v6" />
                </template>
                <template v-else>
                    <path d="M14 22 L32 12 L50 22 L50 46 L32 56 L14 46 Z" :fill="rarity" />
                    <path d="M14 22 L32 32 L50 22 M32 32 V56" fill="none" stroke-width="2.5" />
                </template>
            </g>
        </svg>
    </span>
</template>
