<script setup lang="ts">
import ItemIcon from '@/components/game/ItemIcon.vue';
import AvatarEditor from '@/components/game/AvatarEditor.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import type { Stats } from '@/types';
import type { SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/vue3';
import { Lock, Plus, Sparkles, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Skill {
    key: string;
    name: string;
    description: string;
    mana?: number;
    hours?: number;
}
interface GameClass {
    key: string;
    name: string;
    role: string;
    icon: string;
    description: string;
    focus: string;
    max_hp: number;
    mp_multiplier: number;
    passive: Skill;
    active: Skill;
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
const unlocked = computed(() => props.stats.level >= props.unlock_level);

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
            <h1 class="text-2xl font-extrabold tracking-tight">Held & Klasse</h1>

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
                <section class="rounded-2xl border bg-card p-5">
                    <div class="mb-3 flex items-center justify-between">
                        <h2 class="font-extrabold">Attribute</h2>
                        <span v-if="stats.unspent_points" class="rounded-full bg-amber-500 px-3 py-0.5 text-sm font-bold text-white">
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
                                v-if="stats.unspent_points"
                                class="flex size-9 items-center justify-center rounded-lg bg-amber-500 text-white hover:bg-amber-600"
                                :aria-label="`${a.name} erhöhen`"
                                @click="allocate(a.key)"
                            >
                                <Plus class="size-5" />
                            </button>
                        </div>
                    </div>
                </section>

                <!-- Ausrüstung -->
                <section class="rounded-2xl border bg-card p-5">
                    <h2 class="mb-3 font-extrabold">Ausrüstung</h2>
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                        <div v-for="(label, slot) in slots" :key="slot" class="relative flex flex-col items-center gap-1 rounded-xl border-2 border-dashed p-3 text-center">
                            <span class="text-xs font-semibold text-muted-foreground">{{ label }}</span>
                            <template v-if="equipment[slot]">
                                <ItemIcon :item="{ ...equipment[slot], type: 'equipment', slot }" :size="44" />
                                <span class="text-xs font-bold">{{ equipment[slot].name }}</span>
                                <span class="text-[11px] text-muted-foreground">
                                    {{ Object.entries(equipment[slot].stats ?? {}).map(([k, v]) => `+${v} ${attributeInfo.find((a) => a.key === k)?.name}`).join(', ') }}
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
            <section class="rounded-2xl border bg-card p-5">
                <div v-if="!unlocked" class="flex items-center gap-3 text-muted-foreground">
                    <Lock class="size-6" />
                    <p>Ab <strong>Level {{ unlock_level }}</strong> wählst du deine Klasse – noch {{ unlock_level - stats.level }} Level!</p>
                </div>

                <template v-else-if="hero_class && !choosing">
                    <div class="flex flex-wrap items-start gap-4">
                        <span class="text-6xl">{{ hero_class.icon }}</span>
                        <div class="min-w-0 flex-1">
                            <h2 class="text-xl font-extrabold">{{ hero_class.name }} <span class="text-base font-semibold text-muted-foreground">– {{ hero_class.role }}</span></h2>
                            <p class="text-sm text-muted-foreground">{{ hero_class.description }}</p>
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
                                <p v-else-if="!second_chance_options.length" class="text-sm text-muted-foreground">Gerade wurde nichts verpasst. 🎉</p>
                                <button
                                    v-for="o in second_chance_used ? [] : second_chance_options"
                                    :key="o.assignment_id"
                                    class="flex items-center justify-between rounded-lg border bg-card px-3 py-2 text-left text-sm hover:border-emerald-500"
                                    @click="secondChance(o.assignment_id)"
                                >
                                    <span><strong>{{ o.user }}</strong>: {{ o.title }}</span>
                                    <span class="text-emerald-600">+{{ o.damage }} HP</span>
                                </button>
                            </div>
                        </div>
                        <div class="rounded-xl bg-gradient-to-br from-sky-50 to-indigo-50 p-4 dark:from-sky-950/30 dark:to-indigo-950/30">
                            <p class="text-xs font-bold uppercase text-muted-foreground">Aktiv · {{ hero_class.active.mana }} Mana</p>
                            <p class="font-bold">{{ hero_class.active.name }}</p>
                            <p class="text-sm">{{ hero_class.active.description }}</p>
                            <div class="mt-3 flex flex-wrap items-center gap-2">
                                <select v-if="hero_class.active.key === 'healing_wave'" v-model="target" class="h-9 rounded-md border border-input bg-background px-2 text-sm">
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

                <template v-else>
                    <div class="mb-4 flex items-center justify-between">
                        <h2 class="text-xl font-extrabold">Wähle deine Klasse</h2>
                        <Button v-if="hero_class" variant="ghost" @click="choosing = false">Abbrechen</Button>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        <div v-for="c in classes" :key="c.key" class="flex flex-col gap-2 rounded-2xl border p-4" :class="hero_class?.key === c.key ? 'border-primary' : ''">
                            <div class="flex items-center gap-3">
                                <span class="text-4xl">{{ c.icon }}</span>
                                <div>
                                    <p class="font-extrabold">{{ c.name }}</p>
                                    <p class="text-xs text-muted-foreground">{{ c.role }}</p>
                                </div>
                            </div>
                            <p class="text-sm">{{ c.description }}</p>
                            <p class="text-xs"><strong>{{ c.passive.name }}:</strong> {{ c.passive.description }}</p>
                            <p class="text-xs"><strong>{{ c.active.name }}:</strong> {{ c.active.description }}</p>
                            <p class="text-xs text-muted-foreground">Passt zu: {{ c.focus }}</p>
                            <div class="mt-auto pt-2">
                                <Button v-if="c.choice.allowed && hero_class?.key !== c.key" class="w-full" @click="choose(c)">{{ c.name }} werden</Button>
                                <p v-else-if="hero_class?.key !== c.key" class="text-xs font-semibold text-muted-foreground">{{ c.choice.reason }}</p>
                            </div>
                        </div>
                    </div>
                </template>
            </section>

            <!-- Aktive Buffs -->
            <section v-if="buffs.length" class="grid gap-2">
                <h2 class="font-extrabold">Aktive Effekte</h2>
                <div class="flex flex-wrap gap-2">
                    <span v-for="b in buffs" :key="b.key" class="rounded-full bg-indigo-100 px-3 py-1 text-sm font-semibold text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300">
                        {{ b.name }} · {{ b.armed ? 'bereit' : `noch ${hoursLeft(b.expires_at)} h` }}
                    </span>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
