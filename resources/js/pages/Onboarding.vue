<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthBase from '@/layouts/AuthLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps<{ canCreate: boolean }>();

const join = useForm({ code: '' });
const create = useForm({ name: '' });
</script>

<template>
    <AuthBase title="Willkommen im Heldenhaus!" description="Tritt deiner Familie bei – oder gründe eine neue.">
        <Head title="Familie einrichten" />

        <div class="grid gap-8">
            <form class="grid gap-3" @submit.prevent="join.post(route('onboarding.join'))">
                <Label for="code">Einladungscode</Label>
                <Input id="code" v-model="join.code" placeholder="z. B. 7KD2QX9P1A" class="font-mono uppercase" required />
                <InputError :message="join.errors.code" />
                <Button type="submit" :disabled="join.processing">Familie beitreten</Button>
            </form>

            <template v-if="canCreate">
                <div class="relative text-center text-xs text-muted-foreground">
                    <span class="bg-background px-2">oder</span>
                </div>
                <form class="grid gap-3" @submit.prevent="create.post(route('onboarding.create'))">
                    <Label for="name">Neue Familie gründen</Label>
                    <Input id="name" v-model="create.name" placeholder="Familie Muster" required />
                    <InputError :message="create.errors.name" />
                    <Button type="submit" variant="outline" :disabled="create.processing">Familie gründen</Button>
                </form>
            </template>

            <Link :href="route('logout')" method="post" as="button" class="text-sm text-muted-foreground underline">Abmelden</Link>
        </div>
    </AuthBase>
</template>
