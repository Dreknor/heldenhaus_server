<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { useForm } from '@inertiajs/vue3';

const props = defineProps<{
    council: { enabled: boolean; weekday: number; time: string; free_text: boolean; weekdays: Record<string, string> };
}>();

const form = useForm({
    enabled: props.council.enabled,
    weekday: props.council.weekday,
    time: props.council.time,
    free_text: props.council.free_text,
});
const save = () => form.put(route('council.settings'), { preserveScroll: true });
</script>

<template>
    <Card class="lg:col-span-2">
        <CardHeader>
            <CardTitle>Familienrat und Mitbestimmung</CardTitle>
            <CardDescription>
                Einmal pro Woche etwa 10 Minuten: Rückblick, Danke-Runde, Vorschläge, Abstimmung und Wünsche. Eine Stunde vorher kommt eine
                Erinnerung. Der Rat ist freiwillig.
            </CardDescription>
        </CardHeader>
        <CardContent>
            <form class="grid gap-4 sm:grid-cols-2" @submit.prevent="save">
                <label class="flex items-center gap-2 text-sm sm:col-span-2">
                    <input v-model="form.enabled" type="checkbox" class="size-4" /> Erinnerung an den Familienrat senden
                </label>
                <label class="grid gap-1.5 text-sm">
                    <span>Wochentag</span>
                    <select v-model.number="form.weekday" class="h-9 rounded-md border border-input bg-background px-3">
                        <option v-for="(label, day) in council.weekdays" :key="day" :value="Number(day)">{{ label }}</option>
                    </select>
                    <InputError :message="form.errors.weekday" />
                </label>
                <label class="grid gap-1.5 text-sm">
                    <span>Uhrzeit</span>
                    <Input v-model="form.time" type="time" required />
                    <InputError :message="form.errors.time" />
                </label>
                <label class="flex items-start gap-2 text-sm sm:col-span-2">
                    <input v-model="form.free_text" type="checkbox" class="mt-0.5 size-4" />
                    <span
                        ><strong>Kinder unter 13 dürfen frei schreiben</strong><br /><span class="text-muted-foreground"
                            >Sonst wählen sie Vorschläge, Danke-Gründe und Wünsche aus Vorlagen.</span
                        ></span
                    >
                </label>
                <div class="sm:col-span-2">
                    <Button type="submit" :disabled="form.processing">Familienrat speichern</Button>
                </div>
            </form>
        </CardContent>
    </Card>
</template>
