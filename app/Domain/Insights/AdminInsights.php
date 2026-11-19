<?php

namespace App\Domain\Insights;

use App\Enums\FamilyRole;
use App\Models\ActivityScore;
use App\Models\Family;
use App\Models\FamilyMetric;
use App\Models\Quest;
use App\Models\Survey;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Zahlen für die Weiterentwicklung (Konzept §14.10): nur zusammengefasst über alle Familien –
 * Anzahlen und Mittelwerte, keine Titel, keine Namen, keine IDs einzelner Personen.
 * Dient zum Anpassen von Standardwerten wie Boss-Faktor oder Standard-Aktivitätswerten.
 */
class AdminInsights
{
    public function summary(): array
    {
        $since = CarbonImmutable::now()->subDays(14)->toDateString();
        $members = User::whereNotNull('family_id')->where('family_role', '!=', FamilyRole::Guest->value)->get(['id', 'birth_year', 'family_role']);

        // Aktivitätswerte (Median der letzten 14 Tage) je Altersgruppe, nur Personen mit eigenen Daten
        $scores = ActivityScore::where('date', '>=', $since)->where('sleeping', false)->get(['user_id', 'points', 'units', 'tasks']);
        $byUser = $scores->groupBy('user_id')->filter(fn ($rows) => $rows->count() >= (int) config('game.insights.min_days'));
        $groups = $members->keyBy('id')->map(fn (User $u) => $u->ageGroup()->value);
        $activity = collect(['child', 'teen', 'adult'])->mapWithKeys(function ($group) use ($byUser, $groups) {
            $medians = $byUser->filter(fn ($rows, $userId) => ($groups[$userId] ?? null) === $group)
                ->map(fn ($rows) => ActivityScores::median($rows->pluck('points')->all()));

            return [$group => [
                'people' => $medians->count(),
                'avg_points' => $medians->isEmpty() ? null : round($medians->avg(), 1),
                'default_points' => (float) config("game.insights.default_points.{$group}"),
            ]];
        });

        $quests = Quest::whereIn('status', ['completed', 'expired', 'abandoned'])->where('created_at', '>=', now()->subDays(180))->get(['status', 'progress', 'goal', 'data']);
        $byDifficulty = collect(Difficulty::LEVELS)->mapWithKeys(function ($d) use ($quests) {
            $list = $quests->filter(fn ($q) => ($q->data['difficulty'] ?? 'normal') === $d);
            $finished = $list->whereIn('status', ['completed', 'expired']);

            return [$d => [
                'count' => $list->count(),
                'won' => $list->where('status', 'completed')->count(),
                'win_rate' => $finished->isEmpty() ? null : round($list->where('status', 'completed')->count() / $finished->count(), 2),
                'avg_progress' => $finished->isEmpty() ? null : round($finished->avg(fn ($q) => $q->goal > 0 ? min(1, $q->progress / $q->goal) : 0), 2),
            ]];
        });

        $moods = Survey::where('kind', 'mood')->whereNotNull('answers')->where('date', '>=', now()->subDays(90)->toDateString())->get(['answers']);
        $baseline = Survey::where('kind', 'baseline')->whereNotNull('answers')->orderBy('date')->get(['user_id', 'answers']);
        $first = $baseline->groupBy('user_id')->map->first();
        $latest = $baseline->groupBy('user_id')->filter(fn ($rows) => $rows->count() > 1)->map->last();

        $metrics = FamilyMetric::where('date', '>=', $since)->get(['data']);
        $memberDays = $metrics->flatMap(fn ($m) => array_values($m->data['members'] ?? []));

        return [
            'families' => Family::count(),
            'active_families' => ActivityScore::where('date', '>=', $since)->where('tasks', '>', 0)->distinct()->count('family_id'),
            'members' => [
                'child' => $groups->filter(fn ($g) => $g === 'child')->count(),
                'teen' => $groups->filter(fn ($g) => $g === 'teen')->count(),
                'adult' => $groups->filter(fn ($g) => $g === 'adult')->count(),
                'parents' => $members->where('family_role', FamilyRole::Parent)->count(),
            ],
            'activity' => $activity->all(),
            'quests' => $byDifficulty->all(),
            'metrics' => FamilyMetrics::aggregate($memberDays),
            'mood' => [
                'answers' => $moods->count(),
                'distribution' => collect(range(1, 5))->mapWithKeys(fn ($v) => [$v => $moods->filter(fn ($s) => ($s->answers['mood'] ?? null) === $v)->count()])->all(),
                'average' => $moods->isEmpty() ? null : round($moods->avg(fn ($s) => $s->answers['mood']), 1),
                'reasons' => collect(SurveyService::MOOD_REASONS)->map(fn ($label, $key) => [
                    'key' => $key,
                    'label' => $label,
                    'count' => $moods->filter(fn ($s) => in_array($key, $s->answers['reasons'] ?? [], true))->count(),
                ])->values()->all(),
            ],
            'baseline' => [
                'people' => $first->count(),
                'with_follow_up' => $latest->count(),
                'questions' => collect(SurveyService::BASELINE)->map(fn ($q, $key) => [
                    'key' => $key,
                    'text' => $q['text'],
                    'better' => $q['better'],
                    'before' => $first->isEmpty() ? null : round($first->avg(fn ($s) => $s->answers[$key] ?? 0), 1),
                    'after' => $latest->isEmpty() ? null : round($latest->avg(fn ($s) => $s->answers[$key] ?? 0), 1),
                ])->values()->all(),
            ],
            'settings' => [
                'factors' => config('game.insights.factors'),
                'reward_factor' => config('game.insights.reward_factor'),
            ],
        ];
    }
}
