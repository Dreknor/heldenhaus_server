<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { PiggyBank } from 'lucide-vue-next';

defineProps<{
    accounts: {
        id: number;
        name: string;
        avatar_color: string;
        balance_cents: number;
        entries: { id: number; amount_cents: number; description: string; date: string }[];
    }[];
    isParent: boolean;
}>();

const form = useForm({ user_id: 0, amount: '' as number | '', description: '' });
const euro = (cents: number) => (cents / 100).toLocaleString('de-DE', { style: 'currency', currency: 'EUR' });

function book(userId: number) {
    form.user_id = userId;
    form.post(route('allowance.store'), { preserveScroll: true, onSuccess: () => form.reset() });
}
</script>

<template>
    <Head title="Taschengeld" />

    <AppLayout :breadcrumbs="[{ title: 'Taschengeld', href: '/allowance' }]">
        <div class="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
            <div>
                <h1 class="text-2xl font-extrabold tracking-tight">Taschengeld-Konto</h1>
                <p class="text-sm text-muted-foreground">Nur ein Notizbuch – echtes Geld fließt hier nicht. Belohnungen mit Taschengeld-Betrag werden automatisch gebucht.</p>
            </div>

            <p v-if="!accounts.length" class="rounded-2xl border border-dashed p-10 text-center text-muted-foreground">Noch keine Kinderprofile.</p>

            <div class="grid gap-4 lg:grid-cols-2">
                <section v-for="a in accounts" :key="a.id" class="grid gap-3 rounded-2xl border bg-card p-5">
                    <div class="flex items-center gap-3">
                        <span class="flex size-12 items-center justify-center rounded-xl text-xl font-extrabold text-white" :style="{ background: a.avatar_color }">{{ a.name.charAt(0) }}</span>
                        <div class="flex-1">
                            <p class="font-extrabold">{{ a.name }}</p>
                            <p class="text-sm text-muted-foreground">Kontostand</p>
                        </div>
                        <p class="flex items-center gap-2 text-2xl font-extrabold" :class="a.balance_cents < 0 ? 'text-rose-600' : 'text-emerald-600'">
                            <PiggyBank class="size-6" /> {{ euro(a.balance_cents) }}
                        </p>
                    </div>

                    <form v-if="isParent" class="flex flex-wrap items-start gap-2" @submit.prevent="book(a.id)">
                        <Input v-model.number="form.amount" type="number" step="0.01" placeholder="± Betrag €" class="w-28" />
                        <Input v-model="form.description" placeholder="z. B. Wochen-Taschengeld" class="min-w-40 flex-1" />
                        <Button type="submit" :disabled="form.processing">Buchen</Button>
                        <InputError :message="form.user_id === a.id ? form.errors.amount || form.errors.description : undefined" class="w-full" />
                    </form>

                    <ul class="divide-y text-sm">
                        <li v-for="e in a.entries" :key="e.id" class="flex gap-3 py-2">
                            <span class="text-muted-foreground">{{ new Date(e.date).toLocaleDateString('de-DE') }}</span>
                            <span class="flex-1">{{ e.description }}</span>
                            <span class="font-bold tabular-nums" :class="e.amount_cents < 0 ? 'text-rose-600' : 'text-emerald-600'">{{ euro(e.amount_cents) }}</span>
                        </li>
                        <li v-if="!a.entries.length" class="py-2 text-muted-foreground">Noch keine Buchungen.</li>
                    </ul>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
