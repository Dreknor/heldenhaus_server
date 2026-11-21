<script setup lang="ts">
import SupportSettingsForm from '@/components/settings/SupportSettingsForm.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import type { SupportSettings } from '@/types';
import { computed } from 'vue';

/**
 * Eltern stellen Fokus & Darstellung und die Alltagshilfen für ein Familienmitglied ein. Welche Felder
 * erlaubt sind, kommt vom Server (SupportService::editableBy): Kinder unter 13 komplett, bei Teens und
 * Erwachsenen bleibt das Theme deren eigene Sache.
 */
const props = defineProps<{
    memberId: number;
    memberName: string;
    supportSettings: SupportSettings;
}>();

const themeHidden = computed(() => !props.supportSettings.can_change.includes('theme'));
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle>Fokus & Darstellung für {{ memberName }}</CardTitle>
            <CardDescription>
                Rückmeldung, Lesbarkeit und Alltagshilfen. KI braucht zusätzlich die Freigabe für die ganze Familie.
                <template v-if="themeHidden"> Das Theme wählt {{ memberName }} selbst.</template>
            </CardDescription>
        </CardHeader>
        <CardContent>
            <SupportSettingsForm
                :settings="supportSettings"
                :can-change="supportSettings.can_change"
                :action="route('members.support.settings.update', { member: memberId })"
                :who="memberName"
                submit-label="Für dieses Mitglied speichern"
            />
        </CardContent>
    </Card>
</template>
