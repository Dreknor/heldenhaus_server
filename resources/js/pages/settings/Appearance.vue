<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

import AppearanceTabs from '@/components/AppearanceTabs.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import { type BreadcrumbItem, type SharedData } from '@/types';

import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';

const breadcrumbItems: BreadcrumbItem[] = [
    {
        title: 'Hell & Dunkel',
        href: '/settings/appearance',
    },
];

const page = usePage<SharedData>();
const forcedDark = computed(() => !!page.props.auth?.display?.theme_dark);
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Hell & Dunkel" />

        <SettingsLayout>
            <div class="space-y-6">
                <HeadingSmall title="Hell & Dunkel" description="Wähle, ob Heldenhaus hell, dunkel oder wie dein Gerät aussieht." />
                <AppearanceTabs />
                <p v-if="forcedDark" class="text-sm text-muted-foreground">
                    Dein Theme ist dunkel. Hell und Dunkel wirken nur bei hellen Themes. Das Theme änderst du unter
                    <Link :href="route('support.edit')" class="font-semibold underline">Fokus & Darstellung</Link>.
                </p>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
