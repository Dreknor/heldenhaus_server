<script setup lang="ts">
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { CircleAlert, CircleCheck, Download, LoaderCircle, RotateCcw } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface LogEntry {
    at: string;
    level: string;
    message: string;
}

const props = defineProps<{
    version: string;
    channel: string;
    managed: boolean;
    configured: boolean;
    available: { version: string; channel: string; changelog: string | null; publishedAt: string | null } | null;
    releases: { path: string; version: string; current: boolean }[];
    history: { id: number; from: string; to: string; status: string; step: string | null; log: LogEntry[]; created_at: string }[];
    steps: string[];
}>();

const stepLabels: Record<string, string> = {
    download: 'Herunterladen',
    verify: 'Signatur prüfen',
    extract: 'Entpacken',
    backup: 'Datenbank sichern',
    maintenance: 'Wartungsmodus',
    migrate: 'Datenbank aktualisieren',
    switch: 'Umschalten',
    health: 'Gesundheitsprüfung',
    finish: 'Aufräumen',
};

const running = ref(false);
const state = ref<{ id: number; status: string; step: string | null; log: LogEntry[] } | null>(null);
const error = ref<string | null>(null);

const currentIndex = computed(() => (state.value?.step ? props.steps.indexOf(state.value.step) : props.steps.length));

function xsrf(): string {
    const cookie = document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='));
    return cookie ? decodeURIComponent(cookie.split('=')[1]) : '';
}

async function post(url: string, body: object = {}) {
    const response = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
        body: JSON.stringify(body),
    });
    const data = await response.json();
    if (!response.ok) throw new Error(data.message ?? `Fehler ${response.status}`);
    return data;
}

async function install() {
    if (!props.available || !confirm(`Heldenhaus auf ${props.available.version} aktualisieren? Während des Updates ist die Seite kurz im Wartungsmodus.`)) return;
    running.value = true;
    error.value = null;
    try {
        state.value = await post(route('admin.updates.start'), { version: props.available.version });
        while (state.value && !['done', 'failed', 'rolled_back'].includes(state.value.status)) {
            state.value = await post(route('admin.updates.step', { update: state.value.id }));
        }
    } catch (e) {
        error.value = (e as Error).message;
    } finally {
        running.value = false;
        router.reload();
    }
}

function rollback(path: string, version: string) {
    if (!confirm(`Auf Version ${version} zurückschalten? Die Datenbank bleibt unverändert.`)) return;
    router.post(route('admin.updates.rollback'), { path });
}

const statusLabel: Record<string, string> = { done: 'erfolgreich', failed: 'fehlgeschlagen', rolled_back: 'zurückgerollt', running: 'läuft' };
</script>

<template>
    <Head title="System-Updates" />

    <AppLayout :breadcrumbs="[{ title: 'System-Updates', href: '/admin/updates' }]">
        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="flex-1 text-2xl font-extrabold tracking-tight">System-Updates</h1>
                <span class="rounded-lg bg-muted px-3 py-1 font-mono text-sm">Version {{ version }} · {{ channel }}</span>
                <Button variant="outline" :disabled="running" @click="router.post(route('admin.updates.check'))">Nach Updates suchen</Button>
            </div>

            <div v-if="!managed" class="flex gap-3 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm dark:bg-amber-950/30">
                <CircleAlert class="size-5 shrink-0 text-amber-600" />
                <p>Diese Installation liegt nicht in der Release-Struktur (<code>releases/</code>, <code>shared/</code>, <code>current</code>). Der Online-Updater ist nur auf dem Server verfügbar – siehe <code>docs/deployment.md</code>.</p>
            </div>
            <div v-else-if="!configured" class="flex gap-3 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm dark:bg-amber-950/30">
                <CircleAlert class="size-5 shrink-0 text-amber-600" />
                <p>Release-Quelle oder öffentlicher Schlüssel fehlen (<code>UPDATER_GITHUB_REPOSITORY</code>, <code>UPDATER_PUBLIC_KEY</code>).</p>
            </div>

            <section v-if="available" class="rounded-2xl border bg-card p-5 shadow-sm">
                <div class="flex flex-wrap items-center gap-3">
                    <Download class="size-6 text-emerald-600" />
                    <p class="flex-1 text-lg font-extrabold">Version {{ available.version }} ist verfügbar</p>
                    <Button :disabled="running || !managed" @click="install">
                        <LoaderCircle v-if="running" class="size-4 animate-spin" />
                        Update installieren
                    </Button>
                </div>
                <pre v-if="available.changelog" class="mt-3 whitespace-pre-wrap rounded-lg bg-muted p-3 text-sm">{{ available.changelog }}</pre>
            </section>

            <!-- Fortschritt -->
            <section v-if="state" class="grid gap-3 rounded-2xl border bg-card p-5">
                <ol class="grid gap-1.5">
                    <li v-for="(step, i) in steps" :key="step" class="flex items-center gap-2 text-sm">
                        <CircleCheck v-if="i < currentIndex || state.status === 'done'" class="size-4 text-emerald-600" />
                        <LoaderCircle v-else-if="i === currentIndex && running" class="size-4 animate-spin text-sky-600" />
                        <span v-else class="size-4 rounded-full border" />
                        {{ stepLabels[step] ?? step }}
                    </li>
                </ol>
                <p v-if="error" class="text-sm font-semibold text-rose-600">{{ error }}</p>
            </section>

            <section v-if="releases.length" class="grid gap-2">
                <h2 class="font-extrabold">Installierte Releases</h2>
                <div class="divide-y rounded-xl border bg-card">
                    <div v-for="r in releases" :key="r.path" class="flex items-center gap-3 p-3 text-sm">
                        <span class="font-mono font-bold">{{ r.version }}</span>
                        <span v-if="r.current" class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-bold text-emerald-700">aktiv</span>
                        <button v-else class="ml-auto flex items-center gap-1 rounded-md px-2 py-1 hover:bg-muted" @click="rollback(r.path, r.version)">
                            <RotateCcw class="size-4" /> Zurückschalten
                        </button>
                    </div>
                </div>
            </section>

            <section v-if="history.length" class="grid gap-2">
                <h2 class="font-extrabold">Verlauf</h2>
                <details v-for="h in history" :key="h.id" class="rounded-xl border bg-card p-3 text-sm">
                    <summary class="cursor-pointer">
                        {{ h.created_at }} · {{ h.from }} → {{ h.to }} · <strong>{{ statusLabel[h.status] ?? h.status }}</strong>
                    </summary>
                    <ul class="mt-2 grid gap-1 font-mono text-xs">
                        <li v-for="(entry, i) in h.log" :key="i" :class="{ 'text-rose-600': entry.level === 'error', 'text-amber-600': entry.level === 'warning' }">
                            {{ entry.at.slice(11, 19) }} {{ entry.message }}
                        </li>
                    </ul>
                </details>
            </section>
        </div>
    </AppLayout>
</template>
