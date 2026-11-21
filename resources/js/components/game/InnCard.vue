<script setup lang="ts">
import type { SharedData } from '@/types';
import { router, usePage } from '@inertiajs/vue3';
import { BedDouble } from 'lucide-vue-next';
import { computed, ref } from 'vue';

/** Gasthaus (§1, §11): Pause ohne Folgen. Betreten und Verlassen wie POST/DELETE /api/v1/me/inn. */
const page = usePage<SharedData>();
const sleeping = computed(() => page.props.auth.stats?.sleeping ?? false);
const busy = ref(false);

function toggle() {
    busy.value = true;
    const options = { preserveScroll: true, onFinish: () => (busy.value = false) };
    if (sleeping.value) router.delete(route('web.inn.leave'), options);
    else router.post(route('web.inn.enter'), {}, options);
}
</script>

<template>
    <section class="hh-panel hh-parchment flex flex-col gap-2 p-3 sm:p-4" aria-labelledby="inn-title">
        <h2 id="inn-title" class="hh-panel-title flex items-center gap-2"><BedDouble class="size-5 opacity-80" /> Gasthaus</h2>
        <p class="text-sm">
            <template v-if="sleeping">Du ruhst dich gerade im Gasthaus aus. Deine Serien und Bosse warten auf dich.</template>
            <template v-else>
                Krank, verreist oder einfach müde? Im Gasthaus machst du Pause: Beim Tageswechsel gibt es keinen Schaden, Serien und Bosse warten auf
                dich.
            </template>
        </p>
        <p class="text-xs text-muted-foreground">Wer drei Tage oder länger Pause gemacht hat, wird danach sanft begrüßt.</p>
        <div>
            <button
                class="hh-button border-[var(--wood-edge)] bg-[var(--gold)] px-3 py-1 text-sm text-[var(--wood-edge)] hover:brightness-110 disabled:opacity-60"
                :disabled="busy"
                @click="toggle"
            >
                {{ sleeping ? 'Gasthaus verlassen' : 'Gasthaus betreten' }}
            </button>
        </div>
    </section>
</template>
