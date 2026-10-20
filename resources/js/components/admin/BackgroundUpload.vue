<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { router } from '@inertiajs/vue3';
import { CircleCheck, ImageUp, LoaderCircle, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';

/**
 * Upload eines gekauften Hintergrund-Pakets (ZIP) für die Heldenszene.
 * Lädt in Stücken hoch und lässt den Server die Bilder in Portionen umwandeln,
 * damit weder Upload-Grenzen noch PHP-Laufzeit im Weg stehen.
 */
const props = defineProps<{
    backgrounds: { title: string; author: string | null; count: number } | null;
    /** Vom Server je nach PHP-Upload-Grenze vorgegeben */
    chunkBytes: number;
}>();

const file = ref<File | null>(null);
const phase = ref<'idle' | 'upload' | 'import' | 'done'>('idle');
const progress = ref(0);
const error = ref<string | null>(null);
const result = ref<{ title: string; count: number } | null>(null);
const busy = computed(() => phase.value === 'upload' || phase.value === 'import');

function csrf(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

async function send(url: string, body: FormData) {
    const response = await fetch(url, {
        method: 'POST',
        body,
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': csrf() },
    });
    const data = await response.json().catch(() => ({}));
    return { status: response.status, data };
}

async function start() {
    if (!file.value) return;
    error.value = null;
    result.value = null;
    const upload = crypto.randomUUID();

    try {
        phase.value = 'upload';
        let offset = 0;
        while (offset < file.value.size) {
            const body = new FormData();
            body.append('upload', upload);
            body.append('offset', String(offset));
            body.append('chunk', file.value.slice(offset, offset + props.chunkBytes), 'chunk');
            const { status, data } = await send(route('admin.updates.backgrounds.chunk'), body);
            if (status === 409 || status === 200) offset = data.received;
            else throw new Error(data.message ?? `Upload fehlgeschlagen (${status}).`);
            progress.value = offset / file.value.size;
        }

        phase.value = 'import';
        progress.value = 0;
        let from = 0;
        for (;;) {
            const body = new FormData();
            body.append('upload', upload);
            body.append('from', String(from));
            const { status, data } = await send(route('admin.updates.backgrounds.import'), body);
            if (status !== 200) throw new Error(data.message ?? `Entpacken fehlgeschlagen (${status}).`);
            from = data.next;
            progress.value = data.next / data.total;
            if (data.finished) {
                result.value = data.summary;
                break;
            }
        }
        phase.value = 'done';
        file.value = null;
        router.reload({ only: ['backgrounds'] });
    } catch (e) {
        error.value = e instanceof Error ? e.message : String(e);
        phase.value = 'idle';
    }
}

function remove() {
    if (!confirm('Alle Szenen-Hintergründe entfernen? Die Heldenszene zeigt dann wieder die Standard-Landschaft.')) return;
    router.delete(route('admin.updates.backgrounds.destroy'), { preserveScroll: true });
}
</script>

<template>
    <section class="rounded-2xl border bg-card p-5 shadow-sm">
        <h2 class="mb-1 flex items-center gap-2 font-extrabold"><ImageUp class="size-5 text-sky-600" /> Szenen-Hintergründe</h2>
        <p class="mb-3 text-sm text-muted-foreground">
            Gemalte Hintergründe, die nicht weitergegeben werden dürfen (z. B. „Fantasy Forest Backgrounds“ von Lornn), kommen nicht mit dem Update,
            sondern als ZIP hierher. Innenräume werden ausgelassen, die Bilder verkleinert und unter Credits genannt.
        </p>

        <div v-if="props.backgrounds" class="mb-3 flex flex-wrap items-center gap-2 text-sm">
            <CircleCheck class="size-4 text-emerald-600" />
            <span class="flex-1"
                >Installiert: <strong>{{ props.backgrounds.title }}</strong
                ><span v-if="props.backgrounds.author"> von {{ props.backgrounds.author }}</span> ({{ props.backgrounds.count }} Bilder)</span
            >
            <Button variant="outline" size="sm" :disabled="busy" @click="remove"><Trash2 class="size-4" /> Entfernen</Button>
        </div>
        <p v-else class="mb-3 text-sm">Noch keine Hintergründe installiert, die Szene zeigt die Standard-Landschaft.</p>

        <div class="flex flex-wrap items-center gap-2">
            <input
                type="file"
                accept=".zip,application/zip"
                class="min-w-0 flex-1 text-sm file:mr-3 file:rounded-md file:border file:bg-muted file:px-3 file:py-1.5 file:text-sm"
                :disabled="busy"
                @change="file = ($event.target as HTMLInputElement).files?.[0] ?? null"
            />
            <Button :disabled="!file || busy" @click="start">
                <LoaderCircle v-if="busy" class="size-4 animate-spin" />
                <ImageUp v-else class="size-4" />
                {{ props.backgrounds ? 'Ersetzen' : 'Hochladen und entpacken' }}
            </Button>
        </div>

        <div v-if="busy" class="mt-3">
            <p class="mb-1 text-xs text-muted-foreground">
                {{ phase === 'upload' ? 'Hochladen' : 'Entpacken und umwandeln' }} … {{ Math.round(progress * 100) }} %
            </p>
            <div class="h-2 overflow-hidden rounded-full bg-muted">
                <div class="h-full bg-sky-600 transition-all" :style="{ width: `${progress * 100}%` }" />
            </div>
            <p class="mt-1 text-xs text-muted-foreground">Bitte die Seite offen lassen.</p>
        </div>
        <p v-if="phase === 'done' && result" class="mt-3 text-sm text-emerald-700 dark:text-emerald-400">
            {{ result.count }} Hintergründe aus „{{ result.title }}“ installiert. Sie erscheinen ab sofort in der Heldenszene.
        </p>
        <p v-if="error" class="mt-3 text-sm font-semibold text-rose-600">{{ error }}</p>
    </section>
</template>
