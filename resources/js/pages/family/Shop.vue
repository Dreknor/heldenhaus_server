<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import type { SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { Gift, Pencil, Star } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Item {
    id: number;
    title: string;
    description: string | null;
    icon: string;
    star_cost: number;
    limit_per_week: number | null;
    requires_approval: boolean;
    ha_action_key: string | null;
    active: boolean;
}

defineProps<{
    items: Item[];
    history: { id: number; reward: string; user: string; stars: number; status: string; comment: string | null; created_at: string }[];
    isParent: boolean;
}>();

const page = usePage<SharedData>();
const stars = computed(() => page.props.auth.stats?.stars ?? 0);

const open = ref(false);
const editing = ref<Item | null>(null);
const form = useForm({
    title: '',
    description: '',
    star_cost: 5,
    limit_per_week: '' as number | '',
    requires_approval: true as boolean,
    ha_action_key: '',
    active: true as boolean,
});

function edit(item: Item | null) {
    editing.value = item;
    form.defaults({
        title: item?.title ?? '',
        description: item?.description ?? '',
        star_cost: item?.star_cost ?? 5,
        limit_per_week: item?.limit_per_week ?? '',
        requires_approval: item?.requires_approval ?? true,
        ha_action_key: item?.ha_action_key ?? '',
        active: item?.active ?? true,
    });
    form.reset();
    form.clearErrors();
    open.value = true;
}

function save() {
    form.transform((d) => ({ ...d, ha_action_key: d.ha_action_key || null, limit_per_week: d.limit_per_week || null }));
    const options = { preserveScroll: true, onSuccess: () => (open.value = false) };
    if (editing.value) form.put(route('shop.items.update', { item: editing.value.id }), options);
    else form.post(route('shop.items.store'), options);
}

function redeem(item: Item) {
    if (!confirm(`„${item.title}“ für ${item.star_cost} Sterne einlösen?`)) return;
    router.post(route('shop.items.redeem', { item: item.id }), {}, { preserveScroll: true });
}

const statusLabel: Record<string, string> = { pending: 'wartet', approved: 'eingelöst', rejected: 'abgelehnt' };
</script>

<template>
    <Head title="Belohnungsshop" />

    <AppLayout :breadcrumbs="[{ title: 'Belohnungsshop', href: '/shop' }]">
        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="flex-1 text-2xl font-extrabold tracking-tight">Belohnungsshop</h1>
                <span class="flex items-center gap-1.5 rounded-xl bg-violet-100 px-3 py-1.5 font-extrabold text-violet-700 dark:bg-violet-900/40 dark:text-violet-300">
                    <Star class="size-5 fill-current" /> {{ stars }} Sterne
                </span>
                <Button v-if="isParent" @click="edit(null)">+ Belohnung</Button>
            </div>
            <p class="-mt-4 text-sm text-muted-foreground">Sterne gibt es für Familienaufgaben. Hier tauschst du sie gegen echte Belohnungen.</p>

            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <div
                    v-for="item in items"
                    :key="item.id"
                    class="relative flex flex-col gap-3 rounded-2xl border bg-card p-5 shadow-sm"
                    :class="item.active ? '' : 'opacity-50'"
                >
                    <button v-if="isParent" class="absolute right-3 top-3 rounded-md p-1 hover:bg-muted" aria-label="Bearbeiten" @click="edit(item)"><Pencil class="size-4" /></button>
                    <Gift class="size-8 text-violet-500" />
                    <div class="flex-1">
                        <p class="text-lg font-extrabold">{{ item.title }}</p>
                        <p v-if="item.description" class="text-sm text-muted-foreground">{{ item.description }}</p>
                        <p v-if="item.limit_per_week" class="mt-1 text-xs text-muted-foreground">max. {{ item.limit_per_week }}× pro Woche</p>
                    </div>
                    <button
                        class="flex items-center justify-center gap-1.5 rounded-xl bg-violet-600 py-2.5 font-bold text-white transition hover:bg-violet-700 disabled:bg-muted disabled:text-muted-foreground"
                        :disabled="!item.active || stars < item.star_cost"
                        @click="redeem(item)"
                    >
                        <Star class="size-4 fill-current" /> {{ item.star_cost }} einlösen
                    </button>
                </div>
                <p v-if="!items.length" class="rounded-2xl border border-dashed p-8 text-center text-muted-foreground sm:col-span-2 xl:col-span-3">
                    {{ isParent ? 'Lege die erste Belohnung an – z. B. „30 Minuten Konsole“.' : 'Noch keine Belohnungen – frag deine Eltern!' }}
                </p>
            </div>

            <section v-if="history.length" class="grid gap-2">
                <h2 class="font-extrabold">Verlauf</h2>
                <div class="divide-y rounded-xl border bg-card">
                    <div v-for="h in history" :key="h.id" class="flex flex-wrap items-center gap-3 p-3 text-sm">
                        <span class="text-muted-foreground">{{ h.created_at }}</span>
                        <span class="font-semibold">{{ h.user }}: {{ h.reward }}</span>
                        <span v-if="h.comment" class="text-muted-foreground">„{{ h.comment }}“</span>
                        <span
                            class="ml-auto rounded-full px-2 py-0.5 text-xs font-bold"
                            :class="{
                                'bg-sky-100 text-sky-700': h.status === 'pending',
                                'bg-emerald-100 text-emerald-700': h.status === 'approved',
                                'bg-rose-100 text-rose-700': h.status === 'rejected',
                            }"
                        >
                            {{ statusLabel[h.status] }}
                        </span>
                    </div>
                </div>
            </section>
        </div>

        <Dialog v-model:open="open">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{{ editing ? 'Belohnung bearbeiten' : 'Neue Belohnung' }}</DialogTitle>
                </DialogHeader>
                <form class="grid gap-3" @submit.prevent="save">
                    <div class="grid gap-1.5">
                        <Label for="r-title">Titel</Label>
                        <Input id="r-title" v-model="form.title" required placeholder="30 Minuten Konsole" />
                        <InputError :message="form.errors.title" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="r-desc">Beschreibung</Label>
                        <Input id="r-desc" v-model="form.description" />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="grid gap-1.5">
                            <Label for="r-cost">Preis (Sterne)</Label>
                            <Input id="r-cost" v-model.number="form.star_cost" type="number" min="1" />
                            <InputError :message="form.errors.star_cost" />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="r-limit">Max. pro Woche</Label>
                            <Input id="r-limit" v-model.number="form.limit_per_week" type="number" min="1" placeholder="unbegrenzt" />
                        </div>
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="r-ha">Home-Assistant-Aktion (optional)</Label>
                        <Input id="r-ha" v-model="form.ha_action_key" placeholder="z. B. screen_time_30" />
                        <InputError :message="form.errors.ha_action_key" />
                    </div>
                    <label class="flex items-center gap-2 text-sm"><input v-model="form.requires_approval" type="checkbox" class="size-4" /> Eltern müssen bestätigen</label>
                    <label class="flex items-center gap-2 text-sm"><input v-model="form.active" type="checkbox" class="size-4" /> Im Shop sichtbar</label>
                    <DialogFooter>
                        <Button type="submit" :disabled="form.processing">Speichern</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
