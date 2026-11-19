<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import SupportSettingsForm from '@/components/settings/SupportSettingsForm.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import type { BreadcrumbItem, SupportSettings } from '@/types';
import { Head } from '@inertiajs/vue3';

const props = defineProps<{
    settings: SupportSettings;
    ageGroup: 'child' | 'teen' | 'adult';
}>();

const breadcrumbItems: BreadcrumbItem[] = [{ title: 'Fokus & Darstellung', href: '/settings/focus' }];
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Fokus & Darstellung" />

        <SettingsLayout>
            <div class="space-y-6">
                <HeadingSmall
                    title="Fokus & Darstellung"
                    description="So viel Rückmeldung, wie dir guttut. Und so, dass du alles gut lesen kannst."
                />
                <section class="hh-panel hh-parchment p-4">
                    <SupportSettingsForm
                        :settings="props.settings"
                        :can-change="props.settings.can_change"
                        :action="route('support.settings.update')"
                    />
                </section>
                <p v-if="ageGroup === 'child'" class="text-sm text-muted-foreground">
                    Den sanften Modus und die Energiespar-Tage stellen deine Eltern ein.
                </p>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
