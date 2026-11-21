<script setup lang="ts">
import FeatureTour from '@/components/game/FeatureTour.vue';
import FeatureUnlockCard from '@/components/game/FeatureUnlockCard.vue';
import FlashMessages from '@/components/game/FlashMessages.vue';
import AppLayout from '@/layouts/app/AppSidebarLayout.vue';
import type { BreadcrumbItemType } from '@/types';

interface Props {
    breadcrumbs?: BreadcrumbItemType[];
}

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <slot />
        <FlashMessages />
        <FeatureUnlockCard />
        <FeatureTour />
    </AppLayout>
</template>
