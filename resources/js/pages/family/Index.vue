<script setup lang="ts">
import FeedList, { type FeedEntryView } from '@/components/game/FeedList.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { MessageCircle } from 'lucide-vue-next';

defineProps<{
    family: { name: string };
    feed: FeedEntryView[];
}>();
</script>

<template>
    <Head title="Familie" />

    <AppLayout :breadcrumbs="[{ title: 'Familie', href: '/family' }]">
        <div class="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="flex-1 text-2xl font-extrabold tracking-tight">{{ family.name }}</h1>
                <Button variant="outline" as-child>
                    <Link :href="route('social.index')"><MessageCircle class="size-4" /> Familienchat</Link>
                </Button>
            </div>

            <section class="grid gap-3">
                <h2 class="text-lg font-extrabold">Was in der Familie passiert</h2>
                <FeedList :entries="feed" />
            </section>
        </div>
    </AppLayout>
</template>
