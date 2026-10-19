<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { useForm } from '@inertiajs/vue3';
import { Sunrise } from 'lucide-vue-next';

const props = defineProps<{ open: boolean; tasks: { assignment_id: number; title: string }[] }>();

const form = useForm<{ done: number[] }>({ done: [] });

function toggle(id: number) {
    form.done = form.done.includes(id) ? form.done.filter((d) => d !== id) : [...form.done, id];
}

function submit() {
    form.post(route('cron.run'), { preserveScroll: true });
}
</script>

<template>
    <Dialog :open="props.open">
        <DialogContent class="sm:max-w-md" @interact-outside.prevent @escape-key-down.prevent>
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2"><Sunrise class="size-5 text-amber-500" /> Ein neuer Tag!</DialogTitle>
                <DialogDescription>Hast du gestern noch etwas davon geschafft? Hake es ab – ehrlich zählt!</DialogDescription>
            </DialogHeader>

            <div class="grid gap-2">
                <label v-for="t in tasks" :key="t.assignment_id" class="flex cursor-pointer items-center gap-3 rounded-lg border p-3 hover:bg-muted">
                    <input type="checkbox" class="size-5 rounded" :checked="form.done.includes(t.assignment_id)" @change="toggle(t.assignment_id)" />
                    <span class="font-semibold">{{ t.title }}</span>
                </label>
            </div>

            <DialogFooter>
                <Button class="w-full" :disabled="form.processing" @click="submit">Los geht's in den neuen Tag</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
