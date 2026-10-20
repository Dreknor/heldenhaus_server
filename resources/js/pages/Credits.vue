<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';

defineProps<{
    credits: {
        title: string;
        author: string;
        author_url: string;
        source_url: string;
        license: string;
        license_url: string;
        changes: string;
        contributors?: string[];
        details?: { file: string; authors: string[]; licenses: string[]; urls: string[] }[];
    }[];
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
                <p v-if="c.contributors" class="mt-1 text-sm">Grafiken von: {{ c.contributors.join(', ') }}</p>
                <p class="mt-1 text-sm text-muted-foreground">{{ c.changes }}</p>
                <details v-if="c.details" class="mt-2 text-sm">
                    <summary class="cursor-pointer font-semibold">Urheber und Lizenz je Datei ({{ c.details.length }})</summary>
                    <ul class="mt-2 grid gap-1.5">
                        <li v-for="d in c.details" :key="d.file">
                            <code class="text-xs">{{ d.file }}</code
                            >: {{ d.authors.join(', ') }} · {{ d.licenses.join(', ') }}
                            <template v-for="(url, i) in d.urls" :key="url">
                                · <a :href="url" class="underline" target="_blank" rel="noopener">Quelle {{ d.urls.length > 1 ? i + 1 : '' }}</a>
                            </template>
                        </li>
                    </ul>
                </details>
            </li>
        </ul>
    </div>
</template>
