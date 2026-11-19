<script setup lang="ts">
import Emoji from '@/components/game/Emoji.vue';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useCelebrate } from '@/composables/useCelebrate';
import type { SharedData } from '@/types';
import { useForm, usePage } from '@inertiajs/vue3';
import { Lightbulb, Plus } from 'lucide-vue-next';
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Kopf-Ablage überall (Konzept §11.2): schwebender Knopf auf allen Seiten, dieselbe Erfassung wie in
 * DailySupport (Route inbox.store). Tastenkürzel: Alt+K. Einträge landen ohne Kategorie in der Kopf-Ablage.
 */
const page = usePage<SharedData>();
const visible = computed(() => !!page.props.auth?.user && !!page.props.auth?.family && page.props.auth.user.family_role !== 'guest');

const open = ref(false);
const input = ref<InstanceType<typeof Input> | null>(null);
const saved = ref(false);
const { celebrate } = useCelebrate();
const form = useForm({ title: '' });

function show() {
    saved.value = false;
    open.value = true;
    void nextTick(() => (input.value?.$el as HTMLInputElement | undefined)?.focus());
}

function submit() {
    const title = form.title.trim();
    if (!title || form.processing) return;
    form.title = title;
    form.post(route('inbox.store'), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            form.reset();
            saved.value = true;
            celebrate('step', null, 'Gemerkt!');
            setTimeout(() => (open.value = false), 700);
        },
    });
}

function onKey(e: KeyboardEvent) {
    if (e.altKey && !e.ctrlKey && !e.metaKey && e.key.toLowerCase() === 'k' && visible.value) {
        e.preventDefault();
        show();
    }
}

onMounted(() => window.addEventListener('keydown', onKey));
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));
</script>

<template>
    <template v-if="visible">
        <button
            type="button"
            class="hh-button fixed bottom-20 right-4 z-40 size-14 rounded-full border-[var(--wood-edge)] bg-[var(--gold)] p-0 text-[var(--wood-edge)] hover:brightness-110 md:bottom-6 md:right-6"
            aria-label="Kopf-Ablage: schnell etwas merken (Alt+K)"
            title="Kopf-Ablage (Alt+K)"
            aria-keyshortcuts="Alt+K"
            @click="show"
        >
            <Lightbulb class="size-6" />
        </button>

        <Dialog v-model:open="open">
            <DialogContent class="hh-panel hh-parchment max-w-md">
                <DialogHeader>
                    <DialogTitle class="hh-panel-title flex items-center gap-2"><Emoji glyph="💡" /> Kopf-Ablage</DialogTitle>
                    <DialogDescription>Schnell festhalten, später einsortieren.</DialogDescription>
                </DialogHeader>
                <form class="flex gap-2" @submit.prevent="submit">
                    <Input
                        ref="input"
                        v-model="form.title"
                        maxlength="120"
                        placeholder="Was möchtest du festhalten?"
                        aria-label="Eintrag für die Kopf-Ablage"
                        autocomplete="off"
                    />
                    <button
                        class="flex shrink-0 items-center gap-1 rounded-lg bg-primary px-3 py-2 text-sm font-bold text-primary-foreground disabled:opacity-50"
                        :disabled="form.processing || !form.title.trim()"
                    >
                        <Plus class="size-4" /> Merken
                    </button>
                </form>
                <p v-if="form.errors.title" class="text-xs text-destructive">{{ form.errors.title }}</p>
                <p v-else-if="saved" class="text-sm font-semibold text-good" role="status">Liegt in der Kopf-Ablage.</p>
                <p v-else class="text-xs text-muted-foreground">Tipp: Mit Alt+K geht die Kopf-Ablage überall auf.</p>
            </DialogContent>
        </Dialog>
    </template>
</template>
