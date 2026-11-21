<script setup lang="ts">
import Emoji from '@/components/game/Emoji.vue';
import { Button } from '@/components/ui/button';
import type { SupportSettings } from '@/types';
import { useForm } from '@inertiajs/vue3';
import { Check } from 'lucide-vue-next';
import { computed, watch } from 'vue';

/**
 * Fokus & Darstellung (§11.4, Phase 8) als ein Formular – für die eigene Einstellungsseite und für Eltern,
 * die die Werte für ein Kind setzen. Es zeigt und sendet nur Felder, die `canChange` erlaubt
 * (SupportService::editableBy), der Server prüft das noch einmal.
 */
const props = withDefaults(
    defineProps<{
        settings: SupportSettings;
        canChange: string[];
        action: string;
        /** „du“ für die eigene Seite, sonst der Name des Kindes */
        who?: string | null;
        submitLabel?: string;
    }>(),
    { who: null, submitLabel: 'Speichern' },
);

type Field =
    | 'feedback_level'
    | 'sound'
    | 'haptics'
    | 'reduce_motion'
    | 'focus_mode_default'
    | 'timer_style'
    | 'theme'
    | 'high_contrast'
    | 'text_scale'
    | 'dyslexia_font'
    | 'color_vision'
    | 'gentle_mode'
    | 'ai_steps'
    | 'max_steps'
    | 'low_energy_per_week';

function values(s: SupportSettings) {
    return {
        feedback_level: s.feedback_level,
        sound: s.sound,
        haptics: s.haptics,
        reduce_motion: s.reduce_motion,
        focus_mode_default: s.focus_mode_default,
        timer_style: s.timer_style,
        theme: s.theme,
        high_contrast: s.high_contrast,
        text_scale: Number(s.text_scale) || 1,
        dyslexia_font: s.dyslexia_font,
        color_vision: s.color_vision,
        gentle_mode: s.gentle_mode,
        ai_steps: s.ai_steps,
        max_steps: s.max_steps,
        low_energy_per_week: s.low_energy_per_week,
    };
}

const form = useForm(values(props.settings));

watch(
    () => props.settings,
    (s) => {
        form.defaults(values(s));
        form.reset();
    },
);

const can = (field: Field) => props.canChange.includes(field);
const anyOf = (...fields: Field[]) => fields.some(can);
const own = computed(() => !props.who);

function save() {
    form.transform((data) => Object.fromEntries(Object.entries(data).filter(([key]) => can(key as Field)))).put(props.action, {
        preserveScroll: true,
    });
}

const feedbackLevels = [
    { value: 'full', label: 'Voll', hint: 'Konfetti, Klang und Rütteln', glyph: '🎉' },
    { value: 'subtle', label: 'Dezent', hint: 'Wenig Konfetti, leiser Klang', glyph: '✨' },
    { value: 'haptic', label: 'Nur Rütteln', hint: 'Kein Bild, kein Ton', glyph: '📳' },
] as const;

const textScales = [
    { value: 0.9, label: 'Klein' },
    { value: 1, label: 'Normal' },
    { value: 1.15, label: 'Groß' },
    { value: 1.3, label: 'Größer' },
    { value: 1.5, label: 'Riesig' },
];

const colorVisions = [
    { value: 'normal', label: 'Normale Farben' },
    { value: 'deutan', label: 'Rot-Grün-Schwäche (Grün)' },
    { value: 'protan', label: 'Rot-Grün-Schwäche (Rot)' },
    { value: 'tritan', label: 'Blau-Gelb-Schwäche' },
] as const;

/** Vorschaufarben je Theme: Hintergrund, Hauptfarbe, Rahmen (wie in resources/css/app.css). */
const swatches: Record<string, [string, string, string]> = {
    classic: ['#f3e3c3', '#4d7f2f', '#3d2817'],
    candy: ['#f8d8ee', '#d6339a', '#4a2a5e'],
    forest: ['#d2e1c8', '#237a40', '#23402a'],
    ocean: ['#c5e8ef', '#126a9c', '#12385a'],
    minimal: ['#f1f2f4', '#33508f', '#9ca3af'],
    midnight: ['#111531', '#8c6ff0', '#151a3d'],
    neon: ['#0d0a14', '#00e1e6', '#ff2bd6'],
    retro: ['#12200d', '#9bbc0f', '#306230'],
    graphite: ['#1a1b1e', '#f97316', '#3a3d44'],
};
</script>

<template>
    <form class="grid gap-6" @submit.prevent="save">
        <!-- Rückmeldung -->
        <fieldset v-if="anyOf('feedback_level', 'sound', 'haptics', 'reduce_motion')" class="grid gap-3">
            <legend class="mb-1 flex items-center gap-2 text-base font-extrabold"><Emoji glyph="🔔" /> Rückmeldung beim Abhaken</legend>
            <div v-if="can('feedback_level')" class="grid gap-2 sm:grid-cols-3" role="radiogroup" aria-label="Stärke der Rückmeldung">
                <label
                    v-for="level in feedbackLevels"
                    :key="level.value"
                    class="flex cursor-pointer flex-col gap-0.5 rounded-lg border-2 bg-background/70 p-3 text-sm transition has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ring"
                    :class="form.feedback_level === level.value ? 'border-primary' : 'border-border'"
                >
                    <input v-model="form.feedback_level" type="radio" name="feedback_level" :value="level.value" class="sr-only" />
                    <span class="font-bold"><Emoji :glyph="level.glyph" /> {{ level.label }}</span>
                    <span class="text-xs text-muted-foreground">{{ level.hint }}</span>
                </label>
            </div>
            <label v-if="can('sound')" class="flex items-start gap-2 text-sm">
                <input v-model="form.sound" type="checkbox" class="mt-0.5 size-4" />
                <span
                    ><strong>Klänge</strong><br /><span class="text-muted-foreground">Kurze, leise Töne beim Abhaken, Level-up und Fund.</span></span
                >
            </label>
            <label v-if="can('haptics')" class="flex items-start gap-2 text-sm">
                <input v-model="form.haptics" type="checkbox" class="mt-0.5 size-4" />
                <span
                    ><strong>Rütteln</strong><br /><span class="text-muted-foreground"
                        >Das Handy oder Tablet vibriert kurz (wenn es das kann).</span
                    ></span
                >
            </label>
            <label v-if="can('reduce_motion')" class="flex items-start gap-2 text-sm">
                <input v-model="form.reduce_motion" type="checkbox" class="mt-0.5 size-4" />
                <span
                    ><strong>Animationen aus</strong><br /><span class="text-muted-foreground"
                        >Nichts hüpft, blinkt oder fliegt. Ruhiger für die Augen.</span
                    ></span
                >
            </label>
        </fieldset>

        <!-- Fokus -->
        <fieldset v-if="anyOf('focus_mode_default', 'timer_style')" class="grid gap-3">
            <legend class="mb-1 flex items-center gap-2 text-base font-extrabold"><Emoji glyph="🎯" /> Fokus</legend>
            <label v-if="can('focus_mode_default')" class="flex items-start gap-2 text-sm">
                <input v-model="form.focus_mode_default" type="checkbox" class="mt-0.5 size-4" />
                <span
                    ><strong>Mit dem Fokus-Modus starten</strong><br /><span class="text-muted-foreground"
                        >Nur eine Aufgabe, ein Timer und ein großer Knopf.</span
                    ></span
                >
            </label>
            <div v-if="can('timer_style')" class="grid gap-1.5 text-sm">
                <span class="font-bold">Timer zeigt</span>
                <div class="flex gap-2">
                    <label
                        v-for="style in [
                            { value: 'disc', label: '🟠 Scheibe' },
                            { value: 'digits', label: '🔢 Ziffern' },
                        ]"
                        :key="style.value"
                        class="cursor-pointer rounded-lg border-2 bg-background/70 px-3 py-1.5 font-semibold has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ring"
                        :class="form.timer_style === style.value ? 'border-primary' : 'border-border'"
                    >
                        <input v-model="form.timer_style" type="radio" name="timer_style" :value="style.value" class="sr-only" />
                        {{ style.label }}
                    </label>
                </div>
            </div>
        </fieldset>

        <!-- Theme -->
        <fieldset v-if="can('theme') && settings.themes.length" class="grid gap-3">
            <legend class="mb-1 flex items-center gap-2 text-base font-extrabold"><Emoji glyph="🎨" /> Theme</legend>
            <div class="grid grid-cols-2 gap-2 sm:grid-cols-3" role="radiogroup" aria-label="Theme">
                <label
                    v-for="t in settings.themes"
                    :key="t.key"
                    class="relative flex cursor-pointer flex-col gap-1.5 rounded-lg border-2 bg-background/70 p-2 text-sm font-bold has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ring"
                    :class="form.theme === t.key ? 'border-primary' : 'border-border'"
                >
                    <input v-model="form.theme" type="radio" name="theme" :value="t.key" class="sr-only" />
                    <span
                        class="flex h-12 items-end gap-1 rounded-md border-2 p-1.5"
                        :style="{ background: swatches[t.key]?.[0], borderColor: swatches[t.key]?.[2] }"
                        aria-hidden="true"
                    >
                        <span class="h-full w-3 rounded-sm" :style="{ background: swatches[t.key]?.[2] }" />
                        <span class="h-3 flex-1 rounded-sm" :style="{ background: swatches[t.key]?.[1] }" />
                    </span>
                    <span class="flex items-center justify-between gap-1">
                        {{ t.name }}
                        <span v-if="t.dark" class="rounded bg-muted px-1 text-[10px] font-semibold text-muted-foreground">dunkel</span>
                    </span>
                    <Check
                        v-if="form.theme === t.key"
                        class="absolute right-1.5 top-1.5 size-4 rounded-full bg-primary p-0.5 text-primary-foreground"
                    />
                </label>
            </div>
        </fieldset>
        <p v-else-if="own && !can('theme')" class="text-sm text-muted-foreground">Dein Theme stellen deine Eltern ein.</p>

        <!-- Gut lesbar und gut sichtbar -->
        <fieldset v-if="anyOf('high_contrast', 'text_scale', 'dyslexia_font', 'color_vision')" class="grid gap-3">
            <legend class="mb-1 flex items-center gap-2 text-base font-extrabold"><Emoji glyph="👓" /> Gut lesen und sehen</legend>
            <div v-if="can('text_scale')" class="grid gap-1.5 text-sm">
                <span class="font-bold">Schriftgröße</span>
                <div class="flex flex-wrap gap-2">
                    <label
                        v-for="scale in textScales"
                        :key="scale.value"
                        class="cursor-pointer rounded-lg border-2 bg-background/70 px-3 py-1.5 font-semibold has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ring"
                        :class="Math.abs(form.text_scale - scale.value) < 0.01 ? 'border-primary' : 'border-border'"
                        :style="{ fontSize: `${scale.value}em` }"
                    >
                        <input v-model.number="form.text_scale" type="radio" name="text_scale" :value="scale.value" class="sr-only" />
                        {{ scale.label }}
                    </label>
                </div>
            </div>
            <label v-if="can('high_contrast')" class="flex items-start gap-2 text-sm">
                <input v-model="form.high_contrast" type="checkbox" class="mt-0.5 size-4" />
                <span><strong>Starker Kontrast</strong><br /><span class="text-muted-foreground">Dunklere Schrift und deutliche Ränder.</span></span>
            </label>
            <label v-if="can('dyslexia_font')" class="flex items-start gap-2 text-sm">
                <input v-model="form.dyslexia_font" type="checkbox" class="mt-0.5 size-4" />
                <span
                    ><strong>Lesefreundliche Schrift</strong><br /><span class="text-muted-foreground"
                        >Buchstaben wie b und d sind leichter zu unterscheiden (Atkinson Hyperlegible).</span
                    ></span
                >
            </label>
            <label v-if="can('color_vision')" class="grid gap-1.5 text-sm">
                <span class="font-bold">Farben</span>
                <select v-model="form.color_vision" class="h-9 rounded-md border border-input bg-background px-2 text-sm">
                    <option v-for="cv in colorVisions" :key="cv.value" :value="cv.value">{{ cv.label }}</option>
                </select>
                <span class="text-xs text-muted-foreground">Lebens- und XP-Balken und die Farben der Gewohnheiten werden gut unterscheidbar.</span>
                <span class="flex gap-1" aria-hidden="true">
                    <span class="h-2 w-10 rounded-full bg-gradient-to-r from-stat-hp-from to-stat-hp-to" />
                    <span class="h-2 w-10 rounded-full bg-gradient-to-r from-stat-xp-from to-stat-xp-to" />
                    <span class="h-2 w-10 rounded-full bg-gradient-to-r from-stat-mp-from to-stat-mp-to" />
                    <span v-for="n in 7" :key="n" class="h-2 w-3 rounded-sm" :class="`bg-value-${n}`" />
                </span>
            </label>
        </fieldset>

        <!-- Alltagshilfen (Spielregeln) -->
        <fieldset v-if="anyOf('gentle_mode', 'ai_steps', 'max_steps', 'low_energy_per_week')" class="grid gap-3">
            <legend class="mb-1 flex items-center gap-2 text-base font-extrabold"><Emoji glyph="🛟" /> Alltagshilfen</legend>
            <label v-if="can('gentle_mode')" class="flex items-start gap-2 text-sm">
                <input v-model="form.gentle_mode" type="checkbox" class="mt-0.5 size-4" />
                <span
                    ><strong>Sanfter Modus</strong><br /><span class="text-muted-foreground"
                        >Verpasste tägliche Aufgaben kosten keine Lebenspunkte.</span
                    ></span
                >
            </label>
            <label v-if="can('ai_steps')" class="flex items-start gap-2 text-sm">
                <input v-model="form.ai_steps" type="checkbox" class="mt-0.5 size-4" />
                <span
                    ><strong>KI-Schrittvorschläge erlauben</strong><br /><span class="text-muted-foreground"
                        >Bei mittleren und schweren Aufgaben schlägt die KI Schritte vor. Die Familie muss KI zusätzlich freischalten.</span
                    ></span
                >
            </label>
            <div v-if="can('max_steps') || can('low_energy_per_week')" class="grid gap-3 sm:grid-cols-2">
                <label v-if="can('max_steps')" class="grid gap-1.5 text-sm">
                    <span>Höchstens Schritte</span>
                    <input
                        v-model.number="form.max_steps"
                        type="number"
                        min="2"
                        max="10"
                        required
                        class="h-9 rounded-md border border-input bg-background px-2"
                    />
                </label>
                <label v-if="can('low_energy_per_week')" class="grid gap-1.5 text-sm">
                    <span>Energiespar-Tage pro Woche</span>
                    <input
                        v-model.number="form.low_energy_per_week"
                        type="number"
                        min="0"
                        max="7"
                        required
                        class="h-9 rounded-md border border-input bg-background px-2"
                    />
                </label>
            </div>
        </fieldset>

        <div class="flex items-center gap-3">
            <Button type="submit" :disabled="form.processing || !form.isDirty">{{ submitLabel }}</Button>
            <p v-if="form.recentlySuccessful" class="text-sm font-semibold text-good" role="status">Gespeichert</p>
        </div>
        <ul v-if="Object.keys(form.errors).length" class="text-sm text-destructive">
            <li v-for="(error, key) in form.errors" :key="key">{{ error }}</li>
        </ul>
    </form>
</template>
