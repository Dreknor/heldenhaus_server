<script setup lang="ts">
import ReviewCard, { type Review } from '@/components/game/ReviewCard.vue';
import SurveyCard, { type DueSurvey } from '@/components/game/SurveyCard.vue';
import { Link } from '@inertiajs/vue3';
import { Lightbulb } from 'lucide-vue-next';

/** Karten im Heldenpanel: ungelesener Rückblick, fällige Umfrage, Hinweise für Eltern (§14.5, §14.10). */
export interface BoardInsights {
    review: Review | null;
    survey: DueSurvey | null;
    hints: number;
}

defineProps<{ insights?: BoardInsights }>();
</script>

<template>
    <template v-if="insights">
        <ReviewCard v-if="insights.review" :review="insights.review" compact markable />
        <SurveyCard v-if="insights.survey" :survey="insights.survey" />
        <Link
            v-if="insights.hints"
            :href="route('family.insights')"
            class="hh-panel flex items-center gap-2 bg-amber-100 p-3 text-sm font-bold text-amber-900 dark:bg-amber-900/40 dark:text-amber-100"
        >
            <Lightbulb class="size-5" />
            {{ insights.hints === 1 ? 'Ein Hinweis' : `${insights.hints} Hinweise` }} unter „So läuft's“
        </Link>
    </template>
</template>
