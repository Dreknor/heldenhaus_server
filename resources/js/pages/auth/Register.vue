<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthBase from '@/layouts/AuthLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { LoaderCircle } from 'lucide-vue-next';

const props = defineProps<{ inviteRequired: boolean; invite: string | null }>();

const form = useForm({
    invite: props.invite ?? '',
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post(route('register'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <AuthBase title="Konto erstellen" description="Heldenhaus ist privat – du brauchst einen Einladungscode deiner Familie.">
        <Head title="Registrieren" />

        <form @submit.prevent="submit" class="flex flex-col gap-6">
            <div class="grid gap-6">
                <div class="grid gap-2">
                    <Label for="invite">Einladungscode{{ inviteRequired ? '' : ' (optional)' }}</Label>
                    <Input id="invite" v-model="form.invite" :required="inviteRequired" class="font-mono uppercase" placeholder="z. B. 7KD2QX9P1A" />
                    <InputError :message="form.errors.invite" />
                </div>

                <div class="grid gap-2">
                    <Label for="name">Name</Label>
                    <Input id="name" type="text" required autofocus autocomplete="name" v-model="form.name" placeholder="Vorname" />
                    <InputError :message="form.errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="email">E-Mail-Adresse</Label>
                    <Input id="email" type="email" required autocomplete="email" v-model="form.email" placeholder="email@example.com" />
                    <InputError :message="form.errors.email" />
                </div>

                <div class="grid gap-2">
                    <Label for="password">Passwort</Label>
                    <Input id="password" type="password" required autocomplete="new-password" v-model="form.password" />
                    <InputError :message="form.errors.password" />
                </div>

                <div class="grid gap-2">
                    <Label for="password_confirmation">Passwort wiederholen</Label>
                    <Input id="password_confirmation" type="password" required autocomplete="new-password" v-model="form.password_confirmation" />
                    <InputError :message="form.errors.password_confirmation" />
                </div>

                <Button type="submit" class="mt-2 w-full" :disabled="form.processing">
                    <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                    Konto erstellen
                </Button>
            </div>

            <div class="text-center text-sm text-muted-foreground">
                Schon dabei?
                <TextLink :href="route('login')" class="underline underline-offset-4">Anmelden</TextLink>
            </div>
        </form>
    </AuthBase>
</template>
