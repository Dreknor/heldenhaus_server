<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';

interface AiRequest {
    id: number;
    family_id: number;
    user_id: number | null;
    purpose: string;
    model: string | null;
    prompt_tokens: number;
    completion_tokens: number;
    cost_usd: number;
    ok: boolean;
    response_payload: string | null;
    response_status: number | null;
    finish_reason: string | null;
    error_message: string | null;
    created_at: string;
}

defineProps<{ requests: AiRequest[] }>();

function formatDate(value: string): string {
    return new Date(value).toLocaleString('de-DE', { dateStyle: 'short', timeStyle: 'short' });
}
</script>

<template>
    <Head title="KI-Anfragen" />

    <AppLayout :breadcrumbs="[{ title: 'KI-Anfragen', href: '/admin/ai-requests' }]">
        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <div>
                <h1 class="text-2xl font-extrabold tracking-tight">OpenRouter-Anfragen</h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Die letzten 100 Anfragen. Provider-Antworten können Inhalte aus Aufgaben enthalten und sind deshalb nur für Admins sichtbar.
                </p>
            </div>

            <p v-if="requests.length === 0" class="rounded-xl border p-5 text-sm text-muted-foreground">Noch keine KI-Anfragen protokolliert.</p>

            <article v-for="request in requests" :key="request.id" class="rounded-xl border bg-card p-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="font-bold">
                            Anfrage #{{ request.id }}
                            <span :class="request.ok ? 'text-emerald-600' : 'text-rose-600'">{{
                                request.ok ? '· erfolgreich' : '· fehlgeschlagen'
                            }}</span>
                        </h2>
                        <p class="text-sm text-muted-foreground">
                            {{ formatDate(request.created_at) }} · {{ request.model ?? 'Modell unbekannt' }} · Familie {{ request.family_id }} ·
                            Mitglied {{ request.user_id ?? 'gelöscht' }}
                        </p>
                    </div>
                    <dl class="grid grid-cols-2 gap-x-4 text-xs sm:grid-cols-4">
                        <dt class="text-muted-foreground">HTTP</dt>
                        <dd>{{ request.response_status ?? '–' }}</dd>
                        <dt class="text-muted-foreground">Finish</dt>
                        <dd>{{ request.finish_reason ?? '–' }}</dd>
                        <dt class="text-muted-foreground">Tokens</dt>
                        <dd>{{ request.prompt_tokens }} / {{ request.completion_tokens }}</dd>
                        <dt class="text-muted-foreground">Kosten</dt>
                        <dd>${{ Number(request.cost_usd).toFixed(6) }}</dd>
                    </dl>
                </div>

                <p v-if="request.error_message" class="mt-3 rounded-lg bg-rose-50 p-3 text-sm text-rose-800 dark:bg-rose-950/30 dark:text-rose-300">
                    {{ request.error_message }}
                </p>

                <details v-if="request.response_payload" class="mt-3">
                    <summary class="cursor-pointer select-none text-sm font-medium">Provider-Antwort anzeigen</summary>
                    <pre class="mt-2 max-h-96 overflow-auto whitespace-pre-wrap break-all rounded-lg bg-gray-900 p-3 text-xs text-gray-100">{{
                        request.response_payload
                    }}</pre>
                </details>
                <p v-else class="mt-3 text-xs text-muted-foreground">Keine Provider-Antwort aufgezeichnet.</p>
            </article>
        </div>
    </AppLayout>
</template>
