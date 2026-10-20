<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';

defineProps<{
    credits: { title: string; author: string; author_url: string; source_url: string; license: string; license_url: string; changes: string }[];
}>();
</script>

<template>
    <Head title="Credits" />
    <div class="mx-auto max-w-3xl px-6 py-10">
        <Link :href="route('home')" class="text-sm font-semibold text-muted-foreground hover:underline">← Zurück</Link>
        <h1 class="mt-4 text-2xl font-extrabold tracking-tight">Credits</h1>
        <p class="mt-1 text-muted-foreground">
            Heldenhaus verwendet Grafiken und Bibliotheken mit freier Lizenz. Vielen Dank an alle Urheberinnen und Urheber!
        </p>

        <ul class="mt-6 grid gap-3">
            <li v-for="c in credits" :key="c.title" class="rounded-2xl border bg-card p-4">
                <p class="font-bold">{{ c.title }}</p>
                <p class="text-sm">
                    von <a :href="c.author_url" class="underline" target="_blank" rel="noopener">{{ c.author }}</a> ·
                    <a :href="c.license_url" class="underline" target="_blank" rel="noopener">{{ c.license }}</a> ·
                    <a :href="c.source_url" class="underline" target="_blank" rel="noopener">Quelle</a>
                </p>
                <p class="mt-1 text-sm text-muted-foreground">{{ c.changes }}</p>
            </li>
        </ul>
    </div>
</template>
