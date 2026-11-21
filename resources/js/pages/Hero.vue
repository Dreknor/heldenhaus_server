<script setup lang="ts">
import AvatarEditor from '@/components/game/AvatarEditor.vue';
import Emoji from '@/components/game/Emoji.vue';
import FeatureLock from '@/components/game/FeatureLock.vue';
import ItemIcon from '@/components/game/ItemIcon.vue';
import { Button } from '@/components/ui/button';
import { useFeatures } from '@/composables/useFeatures';
import AppLayout from '@/layouts/AppLayout.vue';
import type { SharedData, Stats } from '@/types';
import { Head, router, usePage } from '@inertiajs/vue3';
import { Lock, Plus, Sparkles, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Skill {
    key: string;
    name: string;
    description: string;
    mana?: number;
    hours?: number;
    level?: number;
    kind?: 'passive' | 'active';
    per_day?: number;
    target?: 'member';
}
interface GameClass {
    key: string;
    name: string;
    role: string;
    icon: string;
    description: string;
    focus: string;
    dungeon?: string;
    max_hp: number;
    mp_multiplier: number;
    passive: Skill;
    active: Skill;
    tiers?: Skill[];
    choice: { allowed: boolean; reason: string | null };
}
interface ItemInfo {
    id: number;
    key: string;
    name: string;
    icon: string;
    rarity: string;
    stats: Record<string, number> | null;
}

const props = defineProps<{
    stats: Stats;
    points: Record<string, number>;
    attributes: Record<string, number>;
    hero_class: GameClass | null;
    class_changed_at: string | null;
    unlock_level: number;
    classes: GameClass[];
    skills_today: Record<string, number>;
    second_chance_used: boolean;
    second_chance_options: { assignment_id: number; title: string; user: string; damage: number }[];
    buffs: { key: string; name: string; armed: boolean; expires_at: string }[];
    family_members: { id: number; name: string; avatar_color: string }[];
    equipment: Record<string, ItemInfo>;
    slots: Record<string, string>;
    masteries: {
        category: string;
        name: string;
        label: string;
        icon: string;
        count: number;
        level: number;
        numeral: string | null;
        title: string | null;
        next: number | null;
        progress: number;
    }[];
    title: { level: string | null; mastery: string | null };
    next_title: { level: number; name: string } | null;
}>();

const attributeInfo = [
    { key: 'str', name: 'Kraft', hint: 'mehr Schaden am Familien-Boss', color: 'text-rose-600' },
    { key: 'int', name: 'Köpfchen', hint: 'mehr XP und Mana', color: 'text-sky-600' },
    { key: 'con', name: 'Ausdauer', hint: 'weniger Schaden', color: 'text-emerald-600' },
    { key: 'per', name: 'Spürsinn', hint: 'mehr Gold und Funde', color: 'text-amber-600' },
];

const page = usePage<SharedData>();
const choosing = ref(false);
const target = ref<number | null>(null);
// Freischaltungen (§13): Klassen und Attribut-Punkte kommen mit dem Level (oder früher durch die Eltern)
const { isOpen, entry } = useFeatures();
const unlocked = computed(() => isOpen('classes'));
const attributesOpen = computed(() => isOpen('attributes'));
const classLevel = computed(() => entry('classes')?.level ?? props.unlock_level);

function allocate(attribute: string) {
    router.post(route('hero.allocate'), { attribute }, { preserveScroll: true });
}

function choose(c: GameClass) {
    const change = props.hero_class ? ' Danach kannst du erst in einem Monat wieder wechseln.' : '';
    if (!confirm(`${c.name} werden?${change}`)) return;
    router.post(route('hero.class'), { class: c.key }, { preserveScroll: true, onSuccess: () => (choosing.value = false) });
}

function cast() {
    if (!props.hero_class) return;
    router.post(route('hero.cast'), { skill: props.hero_class.active.key, target_id: target.value }, { preserveScroll: true });
}

// Fähigkeiten-Stufen ab Level 15/20/25 (§14.7)
const tierTargets = ref<Record<string, number | null>>({});
function castTier(skill: Skill) {
    router.post(route('hero.cast'), { skill: skill.key, target_id: tierTargets.value[skill.key] ?? null }, { preserveScroll: true });
}
const tierCastsLeft = (skill: Skill) => (skill.per_day ?? 3) - (props.skills_today[skill.key] ?? 0);

function secondChance(assignmentId: number) {
    router.post(route('hero.second-chance'), { assignment_id: assignmentId }, { preserveScroll: true });
}

const castsLeft = computed(() => (props.hero_class ? 3 - (props.skills_today[props.hero_class.active.key] ?? 0) : 0));
const hoursLeft = (iso: string) => Math.max(0, Math.round((new Date(iso).getTime() - Date.now()) / 3_600_000));
</script>

<template>
    <Head title="Mein Held" />

    <AppLayout :breadcrumbs="[{ title: 'Held & Klasse', href: '/hero' }]">
        <div class="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
            <div>
                <h1 class="text-2xl font-extrabold tracking-tight">Held & Klasse</h1>
                <p v-if="title.level || title.mastery" class="font-semibold italic text-amber-700 dark:text-amber-300">
                    {{ [title.level, title.mastery].filter(Boolean).join(' · ') }}
                </p>
                <p v-if="next_title" class="text-xs text-muted-foreground">
                    Nächster Titel: „{{ next_title.name }}“ ab Level {{ next_title.level }} – noch {{ next_title.level - stats.level }} Level.
                </p>
            </div>

            <section v-if="page.props.auth.appearance" class="rounded-2xl border bg-card p-5">
                <h2 class="mb-3 font-extrabold">Aussehen</h2>
                <AvatarEditor
                    :avatar="page.props.auth.appearance.avatar"
                    :seed="page.props.auth.appearance.seed"
                    :equipment="page.props.auth.appearance.equipment"
                    :class-key="page.props.auth.appearance.class_key"
                />
            </section>

            <div class="grid gap-6 lg:grid-cols-2">
                <!-- Attribute -->
                <section class="rounded-2xl border bg-card p-5" data-tour="hero.attributes">
                    <div class="mb-3 flex items-center justify-between">
                        <h2 class="font-extrabold">Attribute</h2>
                        <span
                            v-if="stats.unspent_points && attributesOpen"
                            class="rounded-full bg-amber-500 px-3 py-0.5 text-sm font-bold text-white"
                        >
                            {{ stats.unspent_points }} Punkt{{ stats.unspent_points > 1 ? 'e' : '' }} frei
                        </span>
                    </div>
                    <div class="grid gap-2">
                        <div v-for="a in attributeInfo" :key="a.key" class="flex items-center gap-3 rounded-xl bg-muted/50 p-3">
                            <div class="flex-1">
                                <p class="font-bold" :class="a.color">{{ a.name }}</p>
                                <p class="text-xs text-muted-foreground">{{ a.hint }}</p>
                            </div>
                            <div class="text-right">
                                <p class="text-xl font-extrabold tabular-nums">{{ attributes[a.key] }}</p>
                                <p v-if="attributes[a.key] !== points[a.key]" class="text-[11px] text-muted-foreground">
                                    {{ points[a.key] }} + {{ attributes[a.key] - points[a.key] }} Ausrüstung
                                </p>
                            </div>
                            <button
                                v-if="stats.unspent_points && attributesOpen"
                                class="flex size-9 items-center justify-center rounded-lg bg-amber-500 text-white hover:bg-amber-600"
                                :aria-label="`${a.name} erhöhen`"
                                @click="allocate(a.key)"
                            >
                                <Plus class="size-5" />
                            </button>
                        </div>
                    </div>
                    <FeatureLock v-if="!attributesOpen" feature="attributes" compact class="mt-3" />
                </section>

                <!-- Ausrüstung -->
                <section class="rounded-2xl border bg-card p-5" data-tour="hero.equipment">
                    <h2 class="mb-3 font-extrabold">Ausrüstung</h2>
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                        <div
                            v-for="(label, slot) in slots"
                            :key="slot"
                            class="relative flex flex-col items-center gap-1 rounded-xl border-2 border-dashed p-3 text-center"
                        >
                            <span class="text-xs font-semibold text-muted-foreground">{{ label }}</span>
                            <template v-if="equipment[slot]">
                                <ItemIcon :item="{ ...equipment[slot], type: 'equipment', slot }" :size="44" />
                                <span class="text-xs font-bold">{{ equipment[slot].name }}</span>
                                <span class="text-[11px] text-muted-foreground">
                                    {{
                                        Object.entries(equipment[slot].stats ?? {})
                                            .map(([k, v]) => `+${v} ${attributeInfo.find((a) => a.key === k)?.name}`)
                                            .join(', ')
                                    }}
                                </span>
                                <button
                                    class="absolute right-1 top-1 rounded p-0.5 hover:bg-muted"
                                    aria-label="Ablegen"
                                    @click="router.post(route('hero.unequip'), { slot }, { preserveScroll: true })"
                                >
                                    <X class="size-3.5" />
                                </button>
                            </template>
                            <span v-else class="py-3 text-2xl text-muted-foreground/40">–</span>
                        </div>
                    </div>
                    <p class="mt-3 text-xs text-muted-foreground">Ausrüstung kaufst und legst du im Inventar an.</p>
                </section>
            </div>

            <!-- Klasse -->
            <section class="rounded-2xl border bg-card p-5" data-tour="hero.class">
                <div v-if="!unlocked" class="flex items-center gap-3 text-muted-foreground">
                    <Lock class="size-6" />
                    <p v-if="entry('classes')?.state === 'deferred'">Deine Klasse wählst du etwas später – das haben deine Eltern so eingestellt.</p>
                    <p v-else>
                        Ab <strong>Level {{ classLevel }}</strong> wählst du deine Klasse – noch {{ Math.max(1, classLevel - stats.level) }} Level!
                    </p>
                </div>

                <template v-else-if="hero_class && !choosing">
                    <div class="flex flex-wrap items-start gap-4">
                        <span class="text-6xl"><Emoji :glyph="hero_class.icon" /></span>
                        <div class="min-w-0 flex-1">
                            <h2 class="text-xl font-extrabold">
                                {{ hero_class.name }} <span class="text-base font-semibold text-muted-foreground">– {{ hero_class.role }}</span>
                            </h2>
                            <p class="text-sm text-muted-foreground">{{ hero_class.description }}</p>
                            <p v-if="hero_class.dungeon" class="mt-1 text-xs text-muted-foreground">
                                <strong>Im Dungeon:</strong> {{ hero_class.dungeon }}
                            </p>
                        </div>
                        <Button variant="outline" @click="choosing = true">Klasse wechseln</Button>
                    </div>

                    <div class="mt-4 grid gap-3 md:grid-cols-2">
                        <div class="rounded-xl bg-muted/50 p-4">
                            <p class="text-xs font-bold uppercase text-muted-foreground">Passiv</p>
                            <p class="font-bold">{{ hero_class.passive.name }}</p>
                            <p class="text-sm">{{ hero_class.passive.description }}</p>
                            <div v-if="hero_class.key === 'healer'" class="mt-3 grid gap-2">
                                <p v-if="second_chance_used" class="text-sm text-muted-foreground">Diese Woche schon genutzt.</p>
                                <p v-else-if="!second_chance_options.length" class="text-sm text-muted-foreground">
                                    Gerade wurde nichts verpasst. 🎉
                                </p>
                                <button
                                    v-for="o in second_chance_used ? [] : second_chance_options"
                                    :key="o.assignment_id"
                                    class="flex items-center justify-between rounded-lg border bg-card px-3 py-2 text-left text-sm hover:border-emerald-500"
                                    @click="secondChance(o.assignment_id)"
                                >
                                    <span
                                        ><strong>{{ o.user }}</strong
                                        >: {{ o.title }}</span
                                    >
                                    <span class="text-emerald-600">+{{ o.damage }} HP</span>
                                </button>
                            </div>
                        </div>
                        <div class="rounded-xl bg-gradient-to-br from-sky-50 to-indigo-50 p-4 dark:from-sky-950/30 dark:to-indigo-950/30">
                            <p class="text-xs font-bold uppercase text-muted-foreground">Aktiv · {{ hero_class.active.mana }} Mana</p>
                            <p class="font-bold">{{ hero_class.active.name }}</p>
                            <p class="text-sm">{{ hero_class.active.description }}</p>
                            <div class="mt-3 flex flex-wrap items-center gap-2">
                                <select
                                    v-if="hero_class.active.key === 'healing_wave'"
                                    v-model="target"
                                    class="h-9 rounded-md border border-input bg-background px-2 text-sm"
                                >
                                    <option :value="null">mich selbst</option>
                                    <option v-for="m in family_members" :key="m.id" :value="m.id">{{ m.name }}</option>
                                </select>
                                <Button :disabled="stats.mp < (hero_class.active.mana ?? 0) || castsLeft <= 0" @click="cast">
                                    <Sparkles class="size-4" /> Einsetzen
                                </Button>
                                <span class="text-xs text-muted-foreground">heute noch {{ castsLeft }}×</span>
                            </div>
                        </div>
                    </div>
                </template>

                <template v-if="hero_class && !choosing && hero_class.tiers?.length">
                    <h3 class="mb-2 mt-5 font-extrabold">Fähigkeiten-Stufen</h3>
                    <div class="grid gap-3 md:grid-cols-3">
                        <div
                            v-for="t in hero_class.tiers"
                            :key="t.key"
                            class="flex flex-col gap-1 rounded-xl border p-4"
                            :class="stats.level >= (t.level ?? 0) ? 'bg-card' : 'bg-muted/40 text-muted-foreground'"
                        >
                            <p class="flex items-center gap-1 text-xs font-bold uppercase text-muted-foreground">
                                <Lock v-if="stats.level < (t.level ?? 0)" class="size-3" />
                                Level {{ t.level }} · {{ t.kind === 'active' ? `Aktiv · ${t.mana} Mana` : 'Passiv' }}
                            </p>
                            <p class="font-bold">{{ t.name }}</p>
                            <p class="text-sm">{{ t.description }}</p>
                            <p v-if="stats.level < (t.level ?? 0)" class="mt-1 text-xs font-semibold">
                                Noch {{ (t.level ?? 0) - stats.level }} Level bis zur Freischaltung.
                            </p>
                            <div v-else-if="t.kind === 'active'" class="mt-2 flex flex-wrap items-center gap-2">
                                <select
                                    v-if="t.target === 'member'"
                                    v-model="tierTargets[t.key]"
                                    class="h-9 rounded-md border border-input bg-background px-2 text-sm"
                                    :aria-label="`Ziel für ${t.name}`"
                                >
                                    <option :value="null">mich selbst</option>
                                    <option v-for="m in family_members" :key="m.id" :value="m.id">{{ m.name }}</option>
                                </select>
                                <Button size="sm" :disabled="stats.mp < (t.mana ?? 0) || tierCastsLeft(t) <= 0" @click="castTier(t)">
                                    <Sparkles class="size-4" /> Einsetzen
                                </Button>
                                <span class="text-xs text-muted-foreground">heute noch {{ Math.max(0, tierCastsLeft(t)) }}×</span>
                            </div>
                        </div>
                    </div>
                </template>

                <template v-if="unlocked && (!hero_class || choosing)">
                    <div class="mb-4 flex items-center justify-between">
                        <h2 class="text-xl font-extrabold">Wähle deine Klasse</h2>
                        <Button v-if="hero_class" variant="ghost" @click="choosing = false">Abbrechen</Button>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        <div
                            v-for="c in classes"
                            :key="c.key"
                            class="flex flex-col gap-2 rounded-2xl border p-4"
                            :class="hero_class?.key === c.key ? 'border-primary' : ''"
                        >
                            <div class="flex items-center gap-3">
                                <span class="text-4xl"><Emoji :glyph="c.icon" /></span>
                                <div>
                                    <p class="font-extrabold">{{ c.name }}</p>
                                    <p class="text-xs text-muted-foreground">{{ c.role }}</p>
                                </div>
                            </div>
                            <p class="text-sm">{{ c.description }}</p>
                            <p class="text-xs">
                                <strong>{{ c.passive.name }}:</strong> {{ c.passive.description }}
                            </p>
                            <p class="text-xs">
                                <strong>{{ c.active.name }}:</strong> {{ c.active.description }}
                            </p>
                            <p v-if="c.dungeon" class="text-xs"><strong>Im Dungeon:</strong> {{ c.dungeon }}</p>
                            <p class="text-xs text-muted-foreground">Passt zu: {{ c.focus }}</p>
                            <div class="mt-auto pt-2">
                                <Button v-if="c.choice.allowed && hero_class?.key !== c.key" class="w-full" @click="choose(c)"
                                    >{{ c.name }} werden</Button
                                >
                                <p v-else-if="hero_class?.key !== c.key" class="text-xs font-semibold text-muted-foreground">{{ c.choice.reason }}</p>
                            </div>
                        </div>
                    </div>
                </template>
            </section>

            <!-- Meisterschaften (§14.7) -->
            <section class="rounded-2xl border bg-card p-5">
                <h2 class="font-extrabold">Meisterschaften</h2>
                <p class="mb-3 text-sm text-muted-foreground">Für jede Aufgabe einer Kategorie geht es ein Stück weiter – bis Stufe V.</p>
                <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-5">
                    <div v-for="m in masteries" :key="m.category" class="flex flex-col gap-1 rounded-xl bg-muted/50 p-3">
                        <p class="flex items-center gap-2 font-bold">
                            <Emoji :glyph="m.icon" /> {{ m.name }}
                            <span v-if="m.numeral" class="ml-auto rounded-full bg-amber-500 px-2 text-xs font-extrabold text-white">{{
                                m.numeral
                            }}</span>
                        </p>
                        <div
                            class="h-2 overflow-hidden rounded-full bg-background"
                            role="progressbar"
                            :aria-valuenow="m.count"
                            :aria-valuemax="m.next ?? m.count"
                        >
                            <div
                                class="h-full rounded-full bg-gradient-to-r from-amber-400 to-orange-500"
                                :style="{ width: `${m.progress * 100}%` }"
                            />
                        </div>
                        <p class="text-xs text-muted-foreground">
                            <template v-if="m.next">{{ m.count }} / {{ m.next }} Aufgaben</template>
                            <template v-else>{{ m.count }} Aufgaben – höchste Stufe!</template>
                        </p>
                    </div>
                </div>
            </section>

            <!-- Aktive Buffs -->
            <section v-if="buffs.length" class="grid gap-2">
                <h2 class="font-extrabold">Aktive Effekte</h2>
                <div class="flex flex-wrap gap-2">
                    <span
                        v-for="b in buffs"
                        :key="b.key"
                        class="rounded-full bg-indigo-100 px-3 py-1 text-sm font-semibold text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300"
                    >
                        {{ b.name }} · {{ b.armed ? 'bereit' : `noch ${hoursLeft(b.expires_at)} h` }}
                    </span>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
