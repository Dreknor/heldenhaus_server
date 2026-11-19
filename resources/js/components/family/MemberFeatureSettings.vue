<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import type { MemberFeatureSettings } from '@/types';
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';

/**
 * Eltern-Einstellung (§13.1): einzelne Funktionen für ein Familienmitglied früher freischalten oder – nur bei
 * Kindern unter 13 – später freischalten, dazu der Schnellstart für Neulinge. Was schon frei ist, bleibt frei.
 */
const props = defineProps<{
    memberId: number;
    memberName: string;
    settings: MemberFeatureSettings;
}>();

type Mode = '' | 'early' | 'defer';
const modes = () => Object.fromEntries(props.settings.features.map((f) => [f.key, (f.override ?? '') as Mode]));

const form = useForm({ modes: modes(), fast_start: props.settings.fast_start });

watch(
    () => props.settings,
    () => {
        form.defaults({ modes: modes(), fast_start: props.settings.fast_start });
        form.reset();
    },
);

const stateLabel: Record<string, string> = {
    unlocked: 'frei',
    available: 'frei',
    deferred: 'aufgeschoben',
    locked: 'noch gesperrt',
};

function save() {
    form.transform((data) => ({
        features: Object.fromEntries(Object.entries(data.modes).map(([key, mode]) => [key, mode === '' ? null : mode])),
        fast_start: data.fast_start,
    })).put(route('family.members.features', { member: props.memberId }), { preserveScroll: true });
}
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle>Freischaltungen für {{ memberName }}</CardTitle>
            <CardDescription>
                Neue Funktionen kommen mit dem Level. Hier kannst du einzelne früher freischalten
                <template v-if="settings.features.some((f) => f.can_defer)">oder für jüngere Kinder etwas später</template>. Aufgaben, Sterne, Shop
                und Alltagshilfen sind immer frei.
            </CardDescription>
        </CardHeader>
        <CardContent>
            <form class="grid gap-3" @submit.prevent="save">
                <p v-if="settings.show_all" class="rounded-md bg-muted p-2 text-sm">{{ memberName }} hat „Alles sofort zeigen“ eingeschaltet.</p>
                <div class="grid gap-2 sm:grid-cols-2">
                    <label v-for="f in settings.features" :key="f.key" class="grid gap-1 rounded-md border p-2 text-sm">
                        <span class="flex items-center justify-between gap-2">
                            <span class="font-semibold">{{ f.title }}</span>
                            <span class="text-xs text-muted-foreground">Level {{ f.level }} · {{ stateLabel[f.state] ?? f.state }}</span>
                        </span>
                        <select
                            v-model="form.modes[f.key]"
                            class="h-9 rounded-md border border-input bg-background px-2 text-sm"
                            :aria-label="`${f.title} für ${memberName}`"
                        >
                            <option value="">Nach Level-Plan</option>
                            <option value="early">Jetzt freischalten</option>
                            <option v-if="f.can_defer || f.override === 'defer'" value="defer">Später freischalten</option>
                        </select>
                    </label>
                </div>
                <label class="flex items-start gap-2 text-sm">
                    <input v-model="form.fast_start" type="checkbox" class="mt-0.5 size-4" />
                    <span
                        ><strong>Schnellstart</strong><br /><span class="text-muted-foreground"
                            >Die Funktionen bis Level 6 kommen jeden Tag eine nach der anderen statt nach Level – gut für Neue in der Familie.</span
                        ></span
                    >
                </label>
                <div>
                    <Button type="submit" :disabled="form.processing || !form.isDirty">Freischaltungen speichern</Button>
                </div>
            </form>
        </CardContent>
    </Card>
</template>
