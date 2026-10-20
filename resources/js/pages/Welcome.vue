<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Head, Link } from '@inertiajs/vue3';
import { CalendarCheck, Gift, Home, Swords } from 'lucide-vue-next';

const features = [
    { icon: Swords, title: 'Aufgaben werden Abenteuer', text: 'Erledigte Aufgaben bringen Erfahrung, Gold und Level-Ups.' },
    { icon: Home, title: 'Gemeinsam als Familie', text: 'Ämterplan, Aufgabenbörse und ein gemeinsamer Boss statt Streit.' },
    { icon: Gift, title: 'Echte Belohnungen', text: 'Sterne aus Familienaufgaben gegen Belohnungen, die Eltern festlegen.' },
    { icon: CalendarCheck, title: 'Ohne Stress', text: 'Sanfte Regeln für Kinder, Pausenmodus und Wochenziele.' },
];
</script>

<template>
    <Head title="Willkommen" />
    <div class="min-h-screen bg-gradient-to-b from-amber-50 via-orange-50 to-rose-50 text-slate-900 dark:from-slate-950 dark:via-slate-900 dark:to-slate-950 dark:text-slate-100">
        <header class="mx-auto flex max-w-5xl items-center justify-between px-6 py-6">
            <div class="flex items-center gap-2 text-xl font-extrabold">
                <span class="flex size-10 items-center justify-center rounded-xl bg-gradient-to-br from-amber-400 to-orange-500 text-white shadow">
                    <AppLogoIcon class="size-6 fill-current" />
                </span>
                Heldenhaus
            </div>
            <nav class="flex gap-2">
                <Link :href="route('family-login')" class="rounded-lg px-4 py-2 font-semibold hover:bg-white/60 dark:hover:bg-white/10">Kinder-Login</Link>
                <Link :href="route('login')" class="rounded-lg bg-slate-900 px-4 py-2 font-semibold text-white hover:bg-slate-700 dark:bg-white dark:text-slate-900">Anmelden</Link>
            </nav>
        </header>

        <main class="mx-auto max-w-5xl px-6 pb-16 pt-10">
            <h1 class="max-w-2xl text-4xl font-extrabold leading-tight tracking-tight sm:text-5xl">
                Aus Aufgaben werden <span class="text-orange-500">Heldentaten</span>.
            </h1>
            <p class="mt-4 max-w-xl text-lg text-slate-600 dark:text-slate-300">
                Das Familien-Rollenspiel für Kinder von 9 bis 16 Jahren und ihre Eltern. Hausaufgaben, Haushalt und gute Gewohnheiten – gemeinsam statt gegeneinander.
            </p>
            <div class="mt-8 flex flex-wrap gap-3">
                <Link :href="route('family-login')" class="rounded-xl bg-orange-500 px-6 py-3 text-lg font-bold text-white shadow hover:bg-orange-600">Los geht's!</Link>
                <Link :href="route('register')" class="rounded-xl border-2 border-slate-900/10 px-6 py-3 text-lg font-bold hover:bg-white/60 dark:border-white/20">Mit Einladung registrieren</Link>
            </div>

            <div class="mt-16 grid gap-4 sm:grid-cols-2">
                <div v-for="f in features" :key="f.title" class="rounded-2xl bg-white/70 p-5 shadow-sm backdrop-blur dark:bg-white/5">
                    <component :is="f.icon" class="mb-2 size-7 text-orange-500" />
                    <p class="font-extrabold">{{ f.title }}</p>
                    <p class="text-sm text-slate-600 dark:text-slate-300">{{ f.text }}</p>
                </div>
            </div>
        </main>
        <footer class="pb-8 text-center text-sm text-slate-500">
            <Link :href="route('credits')" class="hover:underline">Credits &amp; Lizenzen</Link>
        </footer>
    </div>
</template>
