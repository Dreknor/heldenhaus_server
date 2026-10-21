<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import MemberSupportSettings from '@/components/family/MemberSupportSettings.vue';
import HeroAvatar from '@/components/game/HeroAvatar.vue';
import StatBar from '@/components/game/StatBar.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import type { Member } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { Coins, Copy, Star, Trash2, UserPlus } from 'lucide-vue-next';
import { ref } from 'vue';

const props = defineProps<{
    family: {
        id: number;
        name: string;
        login_code: string | null;
        settings: {
            auto_approve_hours: number;
            ko_gold_loss: number;
            damage_factor_child: number;
            damage_factor_teen: number;
            gentle_mode_default: boolean;
            ai_enabled: boolean;
            ai_budget_usd: number;
        } | null;
    };
    members: Member[];
    invites: { id: number; code: string; role: string; role_label: string; email: string | null; expires_at: string }[];
    isParent: boolean;
    openrouterConfigured: boolean;
    haToken: string | null;
    haConnected: boolean;
    haWebhook: {
        url: string;
        events: string[] | null;
        active: boolean;
        last_sent_at: string | null;
        last_status: number | null;
        last_error: string | null;
        failures: number;
    } | null;
    haWebhookSecret: string | null;
    haEvents: Record<string, string>;
    haAudit: {
        id: number;
        direction: 'in' | 'out';
        action: string;
        status: number | null;
        member: string | null;
        details: Record<string, unknown> | null;
        created_at: string;
    }[];
    apiUrl: string;
    weatherSetting: { source: 'none' | 'ha' | 'open_meteo'; lat: number | null; lon: number | null };
}>();

const haTokenApprove = ref(false);
const webhook = useForm({
    url: props.haWebhook?.url ?? '',
    events: props.haWebhook?.events ?? Object.keys(props.haEvents),
    active: props.haWebhook?.active ?? true,
    regenerate_secret: false,
});
const saveWebhook = () =>
    webhook.put(route('family.ha-webhook.save'), { preserveScroll: true, onSuccess: () => (webhook.regenerate_secret = false) });

const starGift = useForm({ member: 0, stars: 1, reason: '' });
const giveStars = (memberId: number) => {
    starGift.member = memberId;
    starGift.post(route('family.members.stars', { member: memberId }), { preserveScroll: true, onSuccess: () => starGift.reset() });
};

const formatTime = (iso: string) => new Date(iso).toLocaleString('de-DE', { dateStyle: 'short', timeStyle: 'short' });

const haYaml = () => `rest:
  - resource: ${props.apiUrl}/ha/state
    headers:
      Authorization: "Bearer ${props.haToken ?? '<TOKEN>'}"
    scan_interval: 60
    sensor:
      - name: "Heldenhaus Familie"
        value_template: "{{ value_json.members | selectattr('all_dailies_done') | list | count }}"
        json_attributes: [members]

rest_command:
  heldenhaus_event:
    url: ${props.apiUrl}/ha/events
    method: POST
    headers:
      Authorization: "Bearer ${props.haToken ?? '<TOKEN>'}"
      Accept: application/json
    content_type: application/json
    payload: '{"event": "{{ event }}"}'`;

const child = useForm({ name: '', birth_year: new Date().getFullYear() - 10, pin: '' });
const invite = useForm({ role: 'parent', email: '' });
const settings = useForm({
    name: props.family.name,
    auto_approve_hours: props.family.settings?.auto_approve_hours ?? 24,
    ko_gold_loss: props.family.settings?.ko_gold_loss ?? 0,
    damage_factor_child: props.family.settings?.damage_factor_child ?? 0.5,
    damage_factor_teen: props.family.settings?.damage_factor_teen ?? 0.75,
});
const supportSettings = useForm({
    gentle_mode_default: props.family.settings?.gentle_mode_default ?? false,
    ai_enabled: props.family.settings?.ai_enabled ?? false,
    ai_budget_usd: props.family.settings?.ai_budget_usd ?? 1,
});
const weather = useForm({
    weather_source: props.weatherSetting.source,
    weather_lat: props.weatherSetting.lat,
    weather_lon: props.weatherSetting.lon,
});

const ageLabel = { child: '9–12', teen: '13–16', adult: 'Erwachsen' } as const;

function copy(text: string) {
    navigator.clipboard?.writeText(text);
}

const familyLoginUrl = () => `${window.location.origin}/family-login?code=${props.family.login_code}`;
</script>

<template>
    <Head title="Familieneinstellungen" />

    <AppLayout :breadcrumbs="[{ title: 'Familie', href: '/family' }, { title: 'Einstellungen', href: '/family/settings' }]">
        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <h1 class="text-2xl font-extrabold tracking-tight">Einstellungen für {{ family.name }}</h1>

            <!-- Mitglieder -->
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <Card v-for="m in members" :key="m.id">
                    <CardContent class="flex gap-4 pt-6">
                        <HeroAvatar
                            :avatar="m.appearance?.avatar"
                            :seed="m.id"
                            :equipment="m.appearance?.equipment"
                            :class-key="m.appearance?.class_key"
                            :size="72"
                            portrait
                            class="shrink-0"
                        />
                        <div class="min-w-0 flex-1 space-y-1.5">
                            <div class="flex items-baseline justify-between gap-2">
                                <p class="truncate text-lg font-bold">{{ m.name }}</p>
                                <span class="text-xs text-muted-foreground">{{ m.role_label }} · {{ ageLabel[m.age_group] }}</span>
                            </div>
                            <template v-if="m.stats">
                                <p class="text-xs font-bold text-orange-600">Level {{ m.stats.level }}</p>
                                <StatBar label="Leben" :value="m.stats.hp" :max="m.stats.max_hp" color="hp" />
                                <StatBar label="Erfahrung" :value="m.stats.xp" :max="m.stats.xp_to_next" color="xp" />
                                <div class="flex gap-3 pt-1 text-sm font-bold">
                                    <span class="flex items-center gap-1 text-amber-600"><Coins class="size-4" />{{ m.stats.gold.toFixed(0) }}</span>
                                    <span class="flex items-center gap-1 text-violet-600"
                                        ><Star class="size-4 fill-current" />{{ m.stats.stars }}</span
                                    >
                                    <button
                                        v-if="isParent && m.role !== 'guest'"
                                        class="ml-auto rounded-full border px-2 text-xs font-semibold text-violet-700 hover:bg-violet-50 dark:text-violet-300 dark:hover:bg-violet-950/40"
                                        title="Sterne als Lob schenken"
                                        :disabled="starGift.processing"
                                        @click="giveStars(m.id)"
                                    >
                                        +1 ⭐
                                    </button>
                                </div>
                            </template>
                            <label v-if="isParent && m.age_group === 'teen'" class="flex items-center gap-2 pt-1 text-xs text-muted-foreground">
                                <input
                                    type="checkbox"
                                    class="size-3.5"
                                    :checked="m.chat_allowed"
                                    @change="
                                        router.put(
                                            route('family.members.update', { member: m.id }),
                                            { name: m.name, chat_allowed: ($event.target as HTMLInputElement).checked },
                                            { preserveScroll: true },
                                        )
                                    "
                                />
                                Freitext im Familienbündnis erlauben
                            </label>
                        </div>
                    </CardContent>
                </Card>
            </div>

            <div v-if="isParent" class="grid gap-6 lg:grid-cols-2">
                <Card class="lg:col-span-2">
                    <CardHeader>
                        <CardTitle>Wetter in der Heldenszene</CardTitle>
                        <CardDescription>
                            Open-Meteo liefert das aktuelle Wetter für den auf 0,1° gerundeten Standort. Bei Aktivierung erscheinen passende
                            Wettereffekte in der Szene. Die Wetterabfrage ist optional und kostenlos.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form class="grid gap-4 sm:grid-cols-2" @submit.prevent="weather.put(route('family.weather.update'), { preserveScroll: true })">
                            <label class="grid gap-1.5 text-sm sm:col-span-2">
                                <span>Wetterquelle</span>
                                <select v-model="weather.weather_source" class="h-9 rounded-md border border-input bg-background px-3">
                                    <option value="none">Nicht aktiviert</option>
                                    <option value="ha">Home Assistant (nur Wetter-Events)</option>
                                    <option value="open_meteo">Open-Meteo (Wetter in der Heldenszene)</option>
                                </select>
                                <InputError :message="weather.errors.weather_source" />
                            </label>
                            <label v-if="weather.weather_source === 'open_meteo'" class="grid gap-1.5 text-sm">
                                <span>Breitengrad</span>
                                <Input v-model.number="weather.weather_lat" type="number" min="-90" max="90" step="0.1" placeholder="z. B. 51.5" />
                                <InputError :message="weather.errors.weather_lat" />
                            </label>
                            <label v-if="weather.weather_source === 'open_meteo'" class="grid gap-1.5 text-sm">
                                <span>Längengrad</span>
                                <Input v-model.number="weather.weather_lon" type="number" min="-180" max="180" step="0.1" placeholder="z. B. 13.4" />
                                <InputError :message="weather.errors.weather_lon" />
                            </label>
                            <div class="sm:col-span-2">
                                <Button type="submit" :disabled="weather.processing">Wetter-Einstellungen speichern</Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>

                <Card class="lg:col-span-2">
                    <CardHeader>
                        <CardTitle>Familienweite Support-Einstellungen</CardTitle>
                        <CardDescription>
                            Die KI-Freigabe gilt zusätzlich zu den persönlichen Einstellungen der einzelnen Mitglieder.
                            <template v-if="openrouterConfigured"> OpenRouter ist auf dem Server konfiguriert.</template>
                            <template v-else>
                                Auf dem Server fehlt noch der OpenRouter-API-Schlüssel; bis dahin werden keine KI-Anfragen gesendet.</template
                            >
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form
                            class="grid gap-4 sm:grid-cols-2"
                            @submit.prevent="supportSettings.put(route('family.support'), { preserveScroll: true })"
                        >
                            <label class="flex items-start gap-2 text-sm sm:col-span-2">
                                <input v-model="supportSettings.ai_enabled" type="checkbox" class="mt-0.5 size-4" />
                                <span
                                    ><strong>OpenRouter für die Familie freigeben</strong><br /><span class="text-muted-foreground"
                                        >Nur Mitglieder mit aktivierter persönlicher KI-Freigabe erhalten automatische Vorschläge.</span
                                    ></span
                                >
                            </label>
                            <label class="grid gap-1.5 text-sm">
                                <span>KI-Monatsbudget (USD)</span>
                                <Input v-model.number="supportSettings.ai_budget_usd" type="number" min="0" max="20" step="0.01" required />
                                <InputError :message="supportSettings.errors.ai_budget_usd" />
                            </label>
                            <label class="flex items-start gap-2 text-sm">
                                <input v-model="supportSettings.gentle_mode_default" type="checkbox" class="mt-0.5 size-4" />
                                <span
                                    ><strong>Sanften Modus standardmäßig aktivieren</strong><br /><span class="text-muted-foreground"
                                        >Gilt für Mitglieder ohne eigene Einstellung.</span
                                    ></span
                                >
                            </label>
                            <div class="sm:col-span-2">
                                <Button type="submit" :disabled="supportSettings.processing">Support-Standards speichern</Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>

                <template v-for="m in members" :key="`support-${m.id}`">
                    <MemberSupportSettings
                        v-if="m.supportSettings && m.role !== 'guest'"
                        :member-id="m.id"
                        :member-name="m.name"
                        :support-settings="m.supportSettings"
                    />
                </template>

                <!-- Kind hinzufügen -->
                <Card>
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2"><UserPlus class="size-5" /> Kinderprofil anlegen</CardTitle>
                        <CardDescription>Kinder brauchen keine E-Mail. Sie melden sich mit dem Familiencode und ihrer PIN an.</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form class="grid gap-3" @submit.prevent="child.post(route('family.children.store'), { onSuccess: () => child.reset() })">
                            <div class="grid gap-1.5">
                                <Label for="child-name">Name</Label>
                                <Input id="child-name" v-model="child.name" required />
                                <InputError :message="child.errors.name" />
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div class="grid gap-1.5">
                                    <Label for="birth">Geburtsjahr</Label>
                                    <Input id="birth" v-model.number="child.birth_year" type="number" required />
                                    <InputError :message="child.errors.birth_year" />
                                </div>
                                <div class="grid gap-1.5">
                                    <Label for="pin">PIN (4–6 Ziffern)</Label>
                                    <Input id="pin" v-model="child.pin" inputmode="numeric" required />
                                    <InputError :message="child.errors.pin" />
                                </div>
                            </div>
                            <Button type="submit" :disabled="child.processing">Anlegen</Button>
                        </form>
                    </CardContent>
                </Card>

                <!-- Zugang & Einladungen -->
                <Card>
                    <CardHeader>
                        <CardTitle>Zugang für die Familie</CardTitle>
                        <CardDescription>Kinder-Login (z. B. auf dem Familien-Tablet) und Einladungen für weitere Erwachsene.</CardDescription>
                    </CardHeader>
                    <CardContent class="grid gap-4">
                        <div class="rounded-xl bg-muted p-3">
                            <p class="text-xs font-semibold text-muted-foreground">Familiencode</p>
                            <div class="flex items-center gap-2">
                                <span class="font-mono text-2xl font-extrabold tracking-widest">{{ family.login_code }}</span>
                                <button class="ml-auto rounded-md p-1.5 hover:bg-background" title="Link kopieren" @click="copy(familyLoginUrl())">
                                    <Copy class="size-4" />
                                </button>
                            </div>
                        </div>

                        <form
                            class="flex flex-wrap items-end gap-2"
                            @submit.prevent="invite.post(route('family.invites.store'), { onSuccess: () => invite.reset() })"
                        >
                            <div class="grid gap-1.5">
                                <Label for="role">Rolle</Label>
                                <select id="role" v-model="invite.role" class="h-9 rounded-md border border-input bg-background px-3 text-sm">
                                    <option value="parent">Elternteil</option>
                                    <option value="guest">Gast (z. B. Großeltern)</option>
                                    <option value="child">Kind/Teen mit E-Mail</option>
                                </select>
                            </div>
                            <div class="grid min-w-40 flex-1 gap-1.5">
                                <Label for="email">E-Mail (optional)</Label>
                                <Input id="email" v-model="invite.email" type="email" />
                            </div>
                            <Button type="submit" :disabled="invite.processing">Einladen</Button>
                        </form>

                        <ul v-if="invites.length" class="divide-y rounded-xl border">
                            <li v-for="i in invites" :key="i.id" class="flex items-center gap-3 p-3 text-sm">
                                <span class="font-mono font-bold">{{ i.code }}</span>
                                <span class="text-muted-foreground"
                                    >{{ i.role_label }}<template v-if="i.email"> · {{ i.email }}</template> · bis {{ i.expires_at }}</span
                                >
                                <button class="ml-auto rounded-md p-1 hover:bg-muted" title="Kopieren" @click="copy(i.code)">
                                    <Copy class="size-4" />
                                </button>
                                <button
                                    class="rounded-md p-1 text-rose-600 hover:bg-muted"
                                    title="Löschen"
                                    @click="router.delete(route('family.invites.destroy', { invite: i.id }))"
                                >
                                    <Trash2 class="size-4" />
                                </button>
                            </li>
                        </ul>
                    </CardContent>
                </Card>

                <!-- Home Assistant -->
                <Card class="lg:col-span-2">
                    <CardHeader>
                        <CardTitle>Home Assistant</CardTitle>
                        <CardDescription>
                            Home Assistant fragt den Familienstatus ab und meldet Ereignisse (z. B. NFC-Tag „nfc_feed_cat“). Aufgaben mit passendem
                            Ereignis-Schlüssel werden dann automatisch und verifiziert erledigt.
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="grid gap-3">
                        <div class="flex flex-wrap items-center gap-3">
                            <span class="text-sm" :class="haConnected ? 'text-emerald-600' : 'text-muted-foreground'">{{
                                haConnected ? 'Token vorhanden' : 'Noch nicht verbunden'
                            }}</span>
                            <label class="flex items-center gap-2 text-sm">
                                <input v-model="haTokenApprove" type="checkbox" class="size-4" />
                                darf Freigaben bestätigen und Sterne schenken
                            </label>
                            <Button variant="outline" @click="router.post(route('family.ha-token'), { approve: haTokenApprove })">{{
                                haConnected ? 'Neues Token erzeugen' : 'Token erzeugen'
                            }}</Button>
                        </div>
                        <div v-if="haToken" class="rounded-xl border border-amber-300 bg-amber-50 p-3 text-sm dark:bg-amber-950/30">
                            <p class="font-semibold">Dein Token – wird nur jetzt angezeigt:</p>
                            <div class="mt-1 flex items-center gap-2">
                                <code class="break-all">{{ haToken }}</code>
                                <button class="rounded-md p-1.5 hover:bg-background" title="Kopieren" @click="copy(haToken)">
                                    <Copy class="size-4" />
                                </button>
                            </div>
                        </div>
                        <details class="text-sm">
                            <summary class="cursor-pointer font-semibold">Vorlage für configuration.yaml</summary>
                            <pre class="mt-2 overflow-x-auto rounded-lg bg-muted p-3 text-xs">{{ haYaml() }}</pre>
                        </details>

                        <div class="grid gap-3 border-t pt-4">
                            <div>
                                <p class="font-semibold">Rückweg: Webhook an Home Assistant</p>
                                <p class="text-sm text-muted-foreground">
                                    Ist Home Assistant von außen erreichbar (Nabu Casa oder eigener Reverse Proxy), meldet das Heldenhaus Ereignisse
                                    sofort, etwa „Belohnung eingelöst“ mit dem Aktions-Schlüssel aus dem Belohnungsshop. Jede Nachricht ist mit
                                    HMAC-SHA256 signiert (<code>X-Heldenhaus-Signature</code> über <code>Zeitstempel.Body</code>).
                                </p>
                            </div>
                            <form class="grid gap-3" @submit.prevent="saveWebhook">
                                <div class="grid gap-1.5">
                                    <Label for="wh-url">Webhook-URL</Label>
                                    <Input id="wh-url" v-model="webhook.url" placeholder="https://xyz.ui.nabu.casa/api/webhook/heldenhaus" />
                                    <InputError :message="webhook.errors.url" />
                                </div>
                                <div class="flex flex-wrap gap-x-4 gap-y-1 text-sm">
                                    <label v-for="(label, key) in haEvents" :key="key" class="flex items-center gap-2">
                                        <input v-model="webhook.events" type="checkbox" :value="key" class="size-4" />
                                        {{ label }}
                                    </label>
                                </div>
                                <div class="flex flex-wrap items-center gap-3 text-sm">
                                    <label class="flex items-center gap-2"
                                        ><input v-model="webhook.active" type="checkbox" class="size-4" /> aktiv</label
                                    >
                                    <label v-if="haWebhook" class="flex items-center gap-2"
                                        ><input v-model="webhook.regenerate_secret" type="checkbox" class="size-4" /> neues Secret</label
                                    >
                                    <Button type="submit" :disabled="webhook.processing">Speichern</Button>
                                    <Button
                                        v-if="haWebhook"
                                        type="button"
                                        variant="outline"
                                        @click="router.post(route('family.ha-webhook.test'), {}, { preserveScroll: true })"
                                        >Test senden</Button
                                    >
                                    <Button
                                        v-if="haWebhook"
                                        type="button"
                                        variant="ghost"
                                        class="text-rose-600"
                                        @click="router.delete(route('family.ha-webhook.destroy'), { preserveScroll: true })"
                                        >Entfernen</Button
                                    >
                                </div>
                            </form>
                            <div v-if="haWebhookSecret" class="rounded-xl border border-amber-300 bg-amber-50 p-3 text-sm dark:bg-amber-950/30">
                                <p class="font-semibold">Webhook-Secret – wird nur jetzt angezeigt (in der HA-Integration eintragen):</p>
                                <div class="mt-1 flex items-center gap-2">
                                    <code class="break-all">{{ haWebhookSecret }}</code>
                                    <button class="rounded-md p-1.5 hover:bg-background" title="Kopieren" @click="copy(haWebhookSecret)">
                                        <Copy class="size-4" />
                                    </button>
                                </div>
                            </div>
                            <p v-if="haWebhook?.last_sent_at" class="text-xs" :class="haWebhook.last_error ? 'text-rose-600' : 'text-emerald-600'">
                                Zuletzt gesendet {{ formatTime(haWebhook.last_sent_at) }}: {{ haWebhook.last_error ?? `HTTP ${haWebhook.last_status}`
                                }}<template v-if="haWebhook.failures > 1"> ({{ haWebhook.failures }} Fehler in Folge)</template>
                            </p>
                        </div>

                        <details v-if="haAudit.length" class="text-sm">
                            <summary class="cursor-pointer font-semibold">Prüfprotokoll (letzte Aktionen)</summary>
                            <ul class="mt-2 divide-y rounded-xl border text-xs">
                                <li v-for="e in haAudit" :key="e.id" class="flex flex-wrap items-center gap-2 p-2">
                                    <span class="font-mono">{{ formatTime(e.created_at) }}</span>
                                    <span class="rounded bg-muted px-1.5">{{ e.direction === 'in' ? 'HA → Heldenhaus' : 'Heldenhaus → HA' }}</span>
                                    <span class="font-semibold">{{ e.action }}</span>
                                    <span v-if="e.member" class="text-muted-foreground">für {{ e.member }}</span>
                                    <span class="ml-auto" :class="e.status && e.status < 300 ? 'text-emerald-600' : 'text-rose-600'">{{
                                        e.status ?? 'kein Kontakt'
                                    }}</span>
                                </li>
                            </ul>
                        </details>
                    </CardContent>
                </Card>

                <!-- Einstellungen -->
                <Card class="lg:col-span-2">
                    <CardHeader>
                        <CardTitle>Spielregeln der Familie</CardTitle>
                        <CardDescription>Wie streng ist das Spiel? Schaden wird für Kinder standardmäßig gedämpft.</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5" @submit.prevent="settings.put(route('family.settings.update'))">
                            <div class="grid gap-1.5">
                                <Label for="fname">Familienname</Label>
                                <Input id="fname" v-model="settings.name" />
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="aah">Auto-Freigabe nach (Std., 0 = nie)</Label>
                                <Input id="aah" v-model.number="settings.auto_approve_hours" type="number" min="0" max="168" />
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="dfc">Schaden 9–12 (0–1)</Label>
                                <Input id="dfc" v-model.number="settings.damage_factor_child" type="number" step="0.05" min="0" max="1" />
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="dft">Schaden 13–16 (0–1)</Label>
                                <Input id="dft" v-model.number="settings.damage_factor_teen" type="number" step="0.05" min="0" max="1" />
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="ko">Goldverlust bei K.O. (0–1)</Label>
                                <Input id="ko" v-model.number="settings.ko_gold_loss" type="number" step="0.05" min="0" max="1" />
                            </div>
                            <div class="sm:col-span-2 lg:col-span-5">
                                <Button type="submit" :disabled="settings.processing">Speichern</Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            </div>
        </div>
    </AppLayout>
</template>
