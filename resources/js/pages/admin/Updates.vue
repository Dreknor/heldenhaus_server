<script setup lang="ts">
import BackgroundUpload from '@/components/admin/BackgroundUpload.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import {
    Circle,
    CircleAlert,
    CircleCheck,
    CircleX,
    Database,
    Download,
    GitBranch,
    HardHat,
    Hourglass,
    LoaderCircle,
    Minus,
    Package,
    Paintbrush,
    Plug,
    RefreshCw,
    RotateCcw,
    TriangleAlert,
} from 'lucide-vue-next';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

interface Commit {
    hash: string;
    short: string;
    date: string;
    author: string;
    subject: string;
}

interface Check {
    checked_at: string;
    remote: string;
    branch: string | null;
    current_branch: string | null;
    branch_matches: boolean;
    current: Commit | null;
    latest: Commit | null;
    behind: number;
    ahead: number;
    commits: Commit[];
    local_changes: string[];
    migrations: number;
    dependencies: boolean;
    assets: boolean;
    fetch_error: string | null;
    error: string | null;
}

interface Step {
    label: string;
    status: 'pending' | 'running' | 'done' | 'skipped' | 'warning' | 'failed';
    message: string | null;
}

interface State {
    status: 'idle' | 'requested' | 'running' | 'success' | 'failed';
    requested_by?: string | null;
    requested_at?: string | null;
    started_at?: string | null;
    finished_at?: string | null;
    message?: string | null;
    error?: string | null;
    steps?: Record<string, Step>;
}

const props = defineProps<{
    check: Check | null;
    state: State;
    log: string;
    maintenance: boolean;
    backgrounds: { title: string; author: string | null; count: number } | null;
    uploadChunkBytes: number;
}>();

const state = ref<State>(props.state);
const log = ref(props.log);
const maintenance = ref(props.maintenance);
const connectionLost = ref(false);
const finishedNow = ref(false);
const logEl = ref<HTMLPreElement | null>(null);
let timer: ReturnType<typeof setTimeout> | undefined;

const busy = computed(() => ['requested', 'running'].includes(state.value.status));

// Nach Inertia-Aktionen (Anfordern, Zurücknehmen) kommen neue Props
watch(
    () => props.state,
    (value) => {
        state.value = value;
        log.value = props.log;
        maintenance.value = props.maintenance;
        if (busy.value) poll();
    },
);

const waitingTooLong = computed(
    () =>
        state.value.status === 'requested' && !!state.value.requested_at && Date.now() - new Date(state.value.requested_at).getTime() > 3 * 60 * 1000,
);
const updatable = computed(() => props.check && !props.check.error && props.check.behind > 0);

function formatDate(value?: string | null): string {
    return value ? new Date(value).toLocaleString('de-DE', { dateStyle: 'short', timeStyle: 'short' }) : '–';
}

function poll() {
    clearTimeout(timer);
    timer = setTimeout(refresh, 3000);
}

async function refresh() {
    try {
        const response = await fetch(route('admin.updates.status'), {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        if (!response.ok) throw new Error(String(response.status));
        const data = await response.json();
        const wasBusy = busy.value;
        state.value = data.state;
        log.value = data.log;
        maintenance.value = data.maintenance;
        connectionLost.value = false;
        if (wasBusy && !busy.value) finishedNow.value = true;
        await nextTick();
        if (logEl.value) logEl.value.scrollTop = logEl.value.scrollHeight;
    } catch {
        // Während composer/Migrationen kann der Server kurzzeitig Fehler liefern
        connectionLost.value = true;
    }
    if (busy.value || connectionLost.value) poll();
}

onMounted(() => {
    if (busy.value) poll();
});
onBeforeUnmount(() => clearTimeout(timer));

function post(name: string, data: Record<string, boolean> = {}, question?: string) {
    if (question && !confirm(question)) return;
    router.post(route(name), data, { preserveScroll: true });
}

function install() {
    post('admin.updates.start', {}, 'Update jetzt installieren? Heldenhaus ist währenddessen für alle im Wartungsmodus.');
}

function rerun() {
    post('admin.updates.start', { full: true }, 'Alle Update-Schritte erneut ausführen? Heldenhaus ist währenddessen im Wartungsmodus.');
}

function endMaintenance() {
    post('admin.updates.up', {}, 'Wartungsmodus wirklich beenden? Bei einem fehlgeschlagenen Update ist die Installation evtl. unvollständig.');
}
</script>

<template>
    <Head title="System-Updates" />

    <AppLayout :breadcrumbs="[{ title: 'System-Updates', href: '/admin/updates' }]">
        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="flex-1 text-2xl font-extrabold tracking-tight">System-Updates</h1>
                <span v-if="check?.current" class="rounded-lg bg-muted px-3 py-1 font-mono text-sm"
                    >{{ check.current.short }} · {{ check.branch }}</span
                >
                <Button variant="outline" :disabled="busy" @click="post('admin.updates.check')"> <RefreshCw class="size-4" /> Jetzt prüfen </Button>
            </div>

            <!-- Wartungsmodus -->
            <div
                v-if="maintenance"
                class="flex flex-wrap items-center gap-3 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm dark:bg-amber-950/30"
            >
                <HardHat class="size-5 shrink-0 text-amber-600" />
                <p class="flex-1">
                    <strong>Heldenhaus befindet sich im Wartungsmodus.</strong>
                    Nur du siehst die Seite weiterhin (Bypass-Cookie).
                </p>
                <Button v-if="state.status !== 'running'" size="sm" class="bg-amber-600 text-white hover:bg-amber-700" @click="endMaintenance">
                    Wartungsmodus beenden
                </Button>
            </div>

            <div class="grid gap-6 lg:grid-cols-2">
                <!-- Installierte Version -->
                <section class="rounded-2xl border bg-card p-5 shadow-sm">
                    <h2 class="mb-3 font-extrabold">Installierte Version</h2>

                    <p v-if="!check" class="text-sm text-muted-foreground">Es wurde noch nicht nach Updates gesucht.</p>
                    <p v-else-if="check.error" class="text-sm font-semibold text-rose-600">Prüfung fehlgeschlagen: {{ check.error }}</p>
                    <template v-else>
                        <dl class="grid grid-cols-[max-content_1fr] gap-x-4 gap-y-1.5 text-sm">
                            <dt class="text-muted-foreground">Branch</dt>
                            <dd class="font-mono">{{ check.remote }}/{{ check.branch }}</dd>
                            <dt class="text-muted-foreground">Commit</dt>
                            <dd>
                                <span class="font-mono">{{ check.current?.short }}</span> vom {{ formatDate(check.current?.date) }}
                            </dd>
                            <dt class="text-muted-foreground">Beschreibung</dt>
                            <dd>{{ check.current?.subject }}</dd>
                            <dt class="text-muted-foreground">Letzte Prüfung</dt>
                            <dd>{{ formatDate(check.checked_at) }}</dd>
                        </dl>

                        <p v-if="!check.branch_matches" class="mt-3 text-sm font-semibold text-rose-600">
                            Ausgecheckt ist „{{ check.current_branch }}“, konfiguriert ist „{{ check.branch }}“ (UPDATER_BRANCH). Ein Update ist so
                            nicht möglich.
                        </p>
                        <div v-if="check.fetch_error" class="mt-3 flex gap-2 text-sm text-amber-700 dark:text-amber-400">
                            <TriangleAlert class="size-4 shrink-0" />
                            <p>
                                Das Repository konnte vom Webserver nicht abgefragt werden; angezeigt wird der zuletzt bekannte Stand.
                                <span class="mt-1 block break-all font-mono text-xs opacity-75">{{ check.fetch_error.slice(0, 300) }}</span>
                            </p>
                        </div>
                    </template>
                </section>

                <!-- Verfügbares Update -->
                <section class="rounded-2xl border bg-card p-5 shadow-sm">
                    <template v-if="updatable && check">
                        <h2 class="mb-3 flex items-center gap-2 font-extrabold">
                            <Download class="size-5 text-emerald-600" /> {{ check.behind }} neue(r) Commit(s) verfügbar
                        </h2>
                        <p class="mb-3 text-sm">
                            Neuester Stand: <span class="font-mono">{{ check.latest?.short }}</span> vom {{ formatDate(check.latest?.date) }}
                        </p>
                        <ul class="mb-4 grid gap-1 text-sm">
                            <li v-if="check.migrations" class="flex items-center gap-2">
                                <Database class="size-4 text-amber-600" /> {{ check.migrations }} Datenbank-Migration(en)
                            </li>
                            <li v-if="check.dependencies" class="flex items-center gap-2">
                                <Package class="size-4 text-muted-foreground" /> Geänderte PHP-Abhängigkeiten (composer install)
                            </li>
                            <li v-if="check.assets" class="flex items-center gap-2">
                                <Paintbrush class="size-4 text-muted-foreground" /> Frontend-Assets werden neu gebaut
                            </li>
                            <li v-if="check.ahead" class="flex items-center gap-2 text-rose-600">
                                <GitBranch class="size-4" /> {{ check.ahead }} lokale(r) Commit(s) – kein Fast-Forward möglich
                            </li>
                            <li v-if="check.local_changes.length" class="flex items-start gap-2 text-amber-700 dark:text-amber-400">
                                <TriangleAlert class="mt-0.5 size-4 shrink-0" /> Lokal geänderte Dateien: {{ check.local_changes.join(', ') }}
                            </li>
                        </ul>
                        <Button class="w-full" :disabled="busy" @click="install"><Download class="size-4" /> Update installieren</Button>
                    </template>
                    <template v-else>
                        <h2 class="mb-3 flex items-center gap-2 font-extrabold">
                            <CircleCheck class="size-5 text-emerald-600" /> Keine neuen Versionen
                        </h2>
                        <p class="mb-4 text-sm text-muted-foreground">
                            Heldenhaus entspricht dem zuletzt geprüften Stand des Repositorys. Nach einem fehlgeschlagenen Update lassen sich alle
                            Schritte erneut ausführen (Abhängigkeiten, Assets, Migrationen, Caches).
                        </p>
                        <Button variant="outline" size="sm" :disabled="busy" @click="rerun"
                            ><RotateCcw class="size-4" /> Update-Schritte erneut ausführen</Button
                        >
                    </template>
                </section>
            </div>

            <!-- Änderungen -->
            <section v-if="check && !check.error && check.commits.length" class="rounded-2xl border bg-card p-5 shadow-sm">
                <h2 class="mb-3 font-extrabold">Änderungen</h2>
                <ul class="divide-y text-sm">
                    <li v-for="commit in check.commits" :key="commit.hash" class="flex gap-3 py-1.5">
                        <span class="shrink-0 font-mono text-muted-foreground">{{ commit.short }}</span>
                        <span class="flex-1">{{ commit.subject }}</span>
                        <span class="hidden shrink-0 text-muted-foreground sm:inline">{{ formatDate(commit.date) }}</span>
                    </li>
                </ul>
            </section>

            <!-- Fortschritt / letzter Lauf -->
            <section v-if="state.status !== 'idle'" class="rounded-2xl border bg-card p-5 shadow-sm">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <h2 class="flex items-center gap-2 font-extrabold">
                        <template v-if="state.status === 'requested'"
                            ><Hourglass class="size-5 text-amber-600" /> Update angefordert – wartet auf den Scheduler</template
                        >
                        <template v-else-if="state.status === 'running'"
                            ><LoaderCircle class="size-5 animate-spin text-sky-600" /> Update läuft …</template
                        >
                        <template v-else-if="state.status === 'success'"
                            ><CircleCheck class="size-5 text-emerald-600" /> Letztes Update erfolgreich</template
                        >
                        <template v-else><CircleX class="size-5 text-rose-600" /> Letztes Update fehlgeschlagen</template>
                    </h2>
                    <Button v-if="state.status === 'requested'" variant="outline" size="sm" @click="post('admin.updates.cancel')"
                        >Anforderung zurücknehmen</Button
                    >
                    <Button v-if="finishedNow" size="sm" @click="router.reload()">Seite neu laden</Button>
                </div>

                <p class="mb-3 text-xs text-muted-foreground">
                    <span v-if="state.requested_by"
                        >Angefordert von {{ state.requested_by }}<span v-if="state.requested_at"> am {{ formatDate(state.requested_at) }}</span
                        >.</span
                    >
                    <span v-if="state.started_at"> Gestartet {{ formatDate(state.started_at) }}.</span>
                    <span v-if="state.finished_at"> Beendet {{ formatDate(state.finished_at) }}.</span>
                </p>

                <p v-if="waitingTooLong" class="mb-3 text-sm text-amber-700 dark:text-amber-400">
                    Das Update wurde noch nicht gestartet. Läuft der Cronjob für <code>php artisan schedule:run</code>? Alternativ auf dem Server
                    ausführen:
                    <code>php artisan updater:run</code>
                </p>
                <p v-if="state.error" class="mb-3 text-sm font-semibold text-rose-600">{{ state.error }}</p>
                <p v-if="state.status === 'success' && state.message" class="mb-3 text-sm text-emerald-700 dark:text-emerald-400">
                    {{ state.message }}
                </p>
                <p v-if="connectionLost" class="mb-3 flex items-center gap-2 text-sm text-amber-700 dark:text-amber-400">
                    <Plug class="size-4" /> Server vorübergehend nicht erreichbar – während des Updates normal. Es wird weiter abgefragt …
                </p>

                <ol v-if="state.steps" class="mb-4 grid gap-1.5 text-sm">
                    <li v-for="(step, key) in state.steps" :key="key" class="flex items-start gap-2">
                        <span class="mt-0.5 flex w-5 shrink-0 justify-center">
                            <Circle v-if="step.status === 'pending'" class="size-4 opacity-40" />
                            <LoaderCircle v-else-if="step.status === 'running'" class="size-4 animate-spin text-sky-600" />
                            <CircleCheck v-else-if="step.status === 'done'" class="size-4 text-emerald-600" />
                            <Minus v-else-if="step.status === 'skipped'" class="size-4 opacity-50" />
                            <CircleAlert v-else-if="step.status === 'warning'" class="size-4 text-amber-600" />
                            <CircleX v-else class="size-4 text-rose-600" />
                        </span>
                        <span class="flex-1">
                            <span :class="{ 'opacity-60': step.status === 'pending' || step.status === 'skipped' }">{{ step.label }}</span>
                            <span v-if="step.message" class="block break-words text-xs text-muted-foreground">{{ step.message }}</span>
                        </span>
                    </li>
                </ol>

                <details :open="state.status === 'running' || state.status === 'failed'">
                    <summary class="mb-2 cursor-pointer select-none text-sm">Protokoll</summary>
                    <pre
                        ref="logEl"
                        class="max-h-[420px] overflow-auto whitespace-pre-wrap break-all rounded-lg bg-gray-900 p-3 text-xs text-gray-100"
                        >{{ log || '(noch keine Ausgabe)' }}</pre
                    >
                </details>
            </section>

            <BackgroundUpload :backgrounds="backgrounds" :chunk-bytes="uploadChunkBytes" />

            <p class="flex gap-2 text-xs text-muted-foreground">
                <CircleAlert class="size-4 shrink-0" />
                <span>
                    Das Update führt der Scheduler aus (Cron: <code>* * * * * php artisan schedule:run</code>) – als Benutzer, dem die Dateien
                    gehören. Vorher wird die Datenbank nach <code>storage/app/updater/backups</code> gesichert. Auf der Konsole:
                    <code>php artisan updater:check</code> und <code>php artisan updater:run</code>.
                </span>
            </p>
        </div>
    </AppLayout>
</template>
