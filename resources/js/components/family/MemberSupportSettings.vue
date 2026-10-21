<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';

const props = defineProps<{
    memberId: number;
    memberName: string;
    supportSettings: {
        gentle_mode: boolean;
        ai_steps: boolean;
        max_steps: number;
        low_energy_per_week: number;
    };
}>();

const form = useForm({
    gentle_mode: props.supportSettings.gentle_mode,
    ai_steps: props.supportSettings.ai_steps,
    max_steps: props.supportSettings.max_steps,
    low_energy_per_week: props.supportSettings.low_energy_per_week,
});

watch(
    () => props.supportSettings,
    (support) => {
        form.defaults({
            gentle_mode: support.gentle_mode,
            ai_steps: support.ai_steps,
            max_steps: support.max_steps,
            low_energy_per_week: support.low_energy_per_week,
        });
        form.reset();
    },
);

function save() {
    form.put(route('members.support.settings.update', { member: props.memberId }), { preserveScroll: true });
}
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle>Alltagshilfen für {{ memberName }}</CardTitle>
            <CardDescription>Persönliche Unterstützungseinstellungen. KI benötigt zusätzlich die Freigabe für die ganze Familie.</CardDescription>
        </CardHeader>
        <CardContent>
            <form class="grid gap-4" @submit.prevent="save">
                <label class="flex items-start gap-2 text-sm">
                    <input v-model="form.gentle_mode" type="checkbox" class="mt-0.5 size-4" />
                    <span
                        ><strong>Sanfter Modus</strong><br /><span class="text-muted-foreground"
                            >Verpasste tägliche Aufgaben verursachen keinen Lebenspunkte-Schaden.</span
                        ></span
                    >
                </label>
                <label class="flex items-start gap-2 text-sm">
                    <input v-model="form.ai_steps" type="checkbox" class="mt-0.5 size-4" />
                    <span
                        ><strong>KI-Schrittvorschläge erlauben</strong><br /><span class="text-muted-foreground"
                            >OpenRouter darf bei mittelschweren und schweren Aufgaben Schritte vorschlagen.</span
                        ></span
                    >
                </label>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="grid gap-1.5 text-sm">
                        <span>Höchstens Schritte</span>
                        <Input v-model.number="form.max_steps" type="number" min="2" max="10" required />
                    </label>
                    <label class="grid gap-1.5 text-sm">
                        <span>Energiespar-Tage pro Woche</span>
                        <Input v-model.number="form.low_energy_per_week" type="number" min="0" max="7" required />
                    </label>
                </div>
                <div class="flex items-center gap-3">
                    <Button type="submit" :disabled="form.processing">Support-Einstellungen speichern</Button>
                    <p v-if="form.recentlySuccessful" class="text-sm text-emerald-600">Gespeichert</p>
                </div>
                <p v-if="form.errors.ai_steps" class="text-sm text-destructive">{{ form.errors.ai_steps }}</p>
                <p v-if="form.errors.max_steps" class="text-sm text-destructive">{{ form.errors.max_steps }}</p>
                <p v-if="form.errors.low_energy_per_week" class="text-sm text-destructive">{{ form.errors.low_energy_per_week }}</p>
            </form>
        </CardContent>
    </Card>
</template>
