<script setup lang="ts">
import Emoji from '@/components/game/Emoji.vue';
import GamePanel from '@/components/game/GamePanel.vue';
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { KeyRound, PartyPopper } from 'lucide-vue-next';

defineProps<{
    age: number | null;
    teen_card: boolean;
    is_teen: boolean;
    themes: { key: string; name: string; dark: boolean }[];
    theme: string;
    chat_allowed: boolean;
    adult_offer: boolean;
    can_become_adult: boolean;
    adult_profile: boolean;
    has_email: boolean;
    show_all: boolean;
}>();

// Theme über die vorhandenen Alltagshilfen-Einstellungen speichern
function chooseTheme(key: string) {
    router.put(route('support.settings.update'), { theme: key }, { preserveScroll: true });
}

const seen = () => router.post(route('hints.dismiss', { key: 'teen' }), {}, { preserveScroll: true });
const later = () => router.post(route('hints.dismiss', { key: 'adult' }), {}, { preserveScroll: true });

const form = useForm({ email: '', password: '', password_confirmation: '' });
const activate = () => form.post(route('adult-profile'), { preserveScroll: true, onSuccess: () => form.reset('password', 'password_confirmation') });
</script>

<template>
    <Head title="Älter werden" />

    <AppLayout :breadcrumbs="[{ title: 'Älter werden', href: '/me/growing-up' }]">
        <div class="flex flex-1 flex-col gap-5 p-4 md:p-6">
            <h1 class="text-2xl font-extrabold tracking-tight">{{ adult_profile ? 'Dein Profil' : 'Älter werden im Heldenhaus' }}</h1>

            <GamePanel v-if="is_teen && !adult_profile" title="Du bist jetzt Teen!" :icon="PartyPopper">
                <p class="text-sm">Dein Held, deine Klasse und alles, was du geschafft hast, bleiben. Ein paar Dinge sind neu:</p>
                <div class="grid gap-3 md:grid-cols-3">
                    <div class="rounded-md border-2 border-[var(--parchment-edge)] bg-background/60 p-3">
                        <p class="font-extrabold"><Emoji glyph="🎨" /> Dein Look</p>
                        <p class="mb-2 text-xs text-muted-foreground">Neue Themes, auch dunkle. Such dir eins aus:</p>
                        <div class="flex flex-wrap gap-1.5">
                            <button
                                v-for="t in themes"
                                :key="t.key"
                                class="rounded-full border px-2 py-0.5 text-xs font-bold"
                                :class="theme === t.key ? 'border-primary bg-primary/10' : 'hover:bg-muted'"
                                @click="chooseTheme(t.key)"
                            >
                                {{ t.name }}
                            </button>
                        </div>
                    </div>
                    <div class="rounded-md border-2 border-[var(--parchment-edge)] bg-background/60 p-3">
                        <p class="font-extrabold"><Emoji glyph="🔒" /> Private Aufgaben</p>
                        <p class="text-xs text-muted-foreground">
                            Eigene Aufgaben kannst du als privat markieren. Deine Eltern sehen dann nur „1 private Aufgabe erledigt“, nicht was es
                            war. Familienaufgaben sind nie privat.
                        </p>
                    </div>
                    <div class="rounded-md border-2 border-[var(--parchment-edge)] bg-background/60 p-3">
                        <p class="font-extrabold"><Emoji glyph="💬" /> Chat</p>
                        <p class="text-xs text-muted-foreground">
                            <template v-if="chat_allowed">Deine Eltern haben den Chat mit befreundeten Familien freigegeben.</template>
                            <template v-else
                                >Freien Chat mit befreundeten Familien gibt es, wenn deine Eltern ihn erlauben. Frag sie einfach.</template
                            >
                        </p>
                    </div>
                </div>
                <div v-if="teen_card">
                    <button class="hh-button border-[#2c4a14] bg-primary text-primary-foreground" @click="seen">Alles klar!</button>
                </div>
            </GamePanel>

            <GamePanel v-if="can_become_adult" title="Erwachsenenprofil" subtitle="Ab 16 – freiwillig" :icon="KeyRound">
                <ul class="list-disc space-y-1 pl-5 text-sm">
                    <li>Eigene Anmeldung mit E-Mail und Passwort statt PIN.</li>
                    <li>Sachlichere Texte und „Alles sofort zeigen“: alle Funktionen ohne Wartezeit.</li>
                    <li>
                        Deine Eltern sehen danach nur noch Familienaufgaben und Sterne. Persönliche Aufgaben, Statistik und Einstellungen bleiben bei
                        dir.
                    </li>
                    <li>Taschengeld-Konto, Erfolge, Held und Fortschritt bleiben erhalten.</li>
                </ul>
                <form class="grid max-w-md gap-3" @submit.prevent="activate">
                    <div class="grid gap-1.5">
                        <Label for="ap-email">E-Mail</Label>
                        <Input id="ap-email" v-model="form.email" type="email" autocomplete="email" required />
                        <InputError :message="form.errors.email" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="ap-pass">Passwort</Label>
                        <Input id="ap-pass" v-model="form.password" type="password" autocomplete="new-password" required />
                        <InputError :message="form.errors.password" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="ap-pass2">Passwort wiederholen</Label>
                        <Input id="ap-pass2" v-model="form.password_confirmation" type="password" autocomplete="new-password" required />
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        <button type="submit" class="hh-button border-[#2c4a14] bg-primary text-primary-foreground" :disabled="form.processing">
                            Erwachsenenprofil einrichten
                        </button>
                        <button v-if="adult_offer" type="button" class="text-sm text-muted-foreground underline" @click="later">Jetzt nicht</button>
                    </div>
                </form>
            </GamePanel>

            <GamePanel v-if="adult_profile" title="Erwachsenenprofil aktiv">
                <p class="text-sm">
                    Du meldest dich mit deiner E-Mail an. „Alles sofort zeigen“ ist {{ show_all ? 'an' : 'aus' }}. Deine Eltern sehen Familienaufgaben
                    und Sterne.
                </p>
            </GamePanel>

            <p v-if="!is_teen && !adult_profile" class="text-sm text-muted-foreground">Hier gibt es Neuigkeiten, sobald du 13 wirst.</p>
        </div>
    </AppLayout>
</template>
