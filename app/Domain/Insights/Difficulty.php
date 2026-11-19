<?php

namespace App\Domain\Insights;

use App\Enums\FamilyRole;
use App\Models\Quest;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Schwierigkeit passt sich an (Konzept §14.6): Boss-HP, Sammel-Ziele und Event-Ziele aus dem
 * Aktivitätswert der Mitspieler – berechnet nur beim Start, nie mittendrin.
 *
 *   Ziel = Summe der Aktivitätswerte × Quest-Tage × Faktor (leicht 0,5 · normal 0,65 · schwer 0,8)
 *
 * begrenzt durch hp_min/hp_max (bzw. goal_min/goal_max) aus quests.json. Bei Familien-Quests wachsen
 * die Grenzen wie bisher mit der Zahl der Mitspieler (× Mitspieler/2, mindestens × 1).
 *
 * Kalibrierung: Solange noch kein Mitspieler eigene Aktivitätsdaten hat (neue Familie, weniger als
 * min_days gespielte Tage), gilt weiter der feste Katalogwert (hp bzw. goal, skaliert nach Mitspielern)
 * mal Faktor/0,65. So bekommt eine neue Familie die vertrauten Werte, „leicht“ und „schwer“ wirken
 * trotzdem. Sobald mindestens eine Person eigene Daten hat, zählt die Formel; Mitglieder ohne eigene
 * Daten gehen mit dem Standardwert ihrer Altersgruppe ein.
 */
class Difficulty
{
    public const LEVELS = ['easy', 'normal', 'hard'];

    public const LABELS = ['easy' => 'Leicht', 'normal' => 'Normal', 'hard' => 'Schwer'];

    public function __construct(private readonly ActivityScores $scores) {}

    public static function factor(string $difficulty): float
    {
        return (float) config("game.insights.factors.{$difficulty}", config('game.insights.factors.normal'));
    }

    public static function rewardFactor(string $difficulty): float
    {
        return (float) config("game.insights.reward_factor.{$difficulty}", 1.0);
    }

    /** @return list<array{key: string, label: string, factor: float, reward_factor: float}> */
    public static function options(): array
    {
        return array_map(fn ($d) => [
            'key' => $d,
            'label' => self::LABELS[$d],
            'factor' => self::factor($d),
            'reward_factor' => self::rewardFactor($d),
        ], self::LEVELS);
    }

    /** Belohnung einer Quest je nach Stufe („schwer“ bringt mehr Gold und XP, Gegenstände bleiben gleich). */
    public static function rewards(array $rewards, string $difficulty): array
    {
        $factor = self::rewardFactor($difficulty);
        foreach (['gold', 'xp'] as $key) {
            if (isset($rewards[$key])) {
                $rewards[$key] = (int) round($rewards[$key] * $factor);
            }
        }

        return $rewards;
    }

    /** @return Collection<int, User> */
    public static function participants(?int $familyId, string $scope, User $user): Collection
    {
        if ($scope === 'solo' || $familyId === null) {
            return collect([$user]);
        }

        return User::where('family_id', $familyId)->where('family_role', '!=', FamilyRole::Guest->value)->get();
    }

    /**
     * Ziel einer Quest (Boss-HP bzw. Anzahl Gegenstände) für die gewählte Stufe.
     *
     * @return array{goal: float, activity: float, own: bool, min: float, max: float}
     */
    public function questGoal(array $definition, string $scope, User $user, string $difficulty = 'normal'): array
    {
        $participants = self::participants($user->family_id, $scope, $user);
        $scale = $scope === 'family' ? max(1, $participants->count() / 2) : 1;
        $boss = $definition['type'] === 'boss';
        $base = (float) ($boss ? $definition['hp'] : $definition['goal']);
        [$lowFactor, $highFactor] = config('game.insights.bounds');
        $min = (float) ($definition[$boss ? 'hp_min' : 'goal_min'] ?? $base * $lowFactor) * $scale;
        $max = (float) ($definition[$boss ? 'hp_max' : 'goal_max'] ?? $base * $highFactor) * $scale;

        $values = $participants->map(fn (User $u) => $this->scores->value($u));
        $own = $values->contains('own', true);
        $factor = self::factor($difficulty);
        $activity = (float) $values->sum($boss ? 'points' : 'units');

        if (! $own) {
            $raw = $base * $scale * $factor / self::factor('normal');
        } elseif ($boss) {
            $raw = $activity * $definition['days'] * $factor;
        } else {
            $raw = $activity * $definition['days'] * $factor * $this->categoryShare($participants, $definition['categories'] ?? []);
        }

        return [
            'goal' => (float) max(1, round(min($max, max($min, $raw)))),
            'activity' => round($activity, 2),
            'own' => $own,
            'min' => round($min),
            'max' => round($max),
        ];
    }

    /** Ziele aller Stufen (für die Quest-Tafel). @return array<string, float> */
    public function questGoals(array $definition, string $scope, User $user): array
    {
        return collect(self::LEVELS)->mapWithKeys(fn ($d) => [$d => $this->questGoal($definition, $scope, $user, $d)['goal']])->all();
    }

    /**
     * Event-Ziel (§12.1) nach derselben Regel mit Stufe „normal“ (Events starten automatisch, ohne Wahl).
     * Grenzen: Hälfte bis Doppeltes des bisherigen Ziels (Punkte je Mitglied und Tag aus events.json).
     */
    public function eventGoal(float $pointsPerMemberDay, Collection $members, int $days): float
    {
        $legacy = $pointsPerMemberDay * max(1, $members->count()) * $days;
        $values = $members->map(fn (User $u) => $this->scores->value($u));
        if (! $values->contains('own', true)) {
            return (float) round($legacy);
        }
        [$lowFactor, $highFactor] = config('game.insights.bounds');
        $raw = $values->sum('points') * $days * self::factor('normal');

        return (float) round(min($legacy * $highFactor, max($legacy * $lowFactor, $raw)));
    }

    /**
     * Vorschlag der Quest-Tafel: zweimal hintereinander knapp verloren → „leicht“,
     * dreimal hintereinander deutlich gewonnen → „schwer“ (mit besserer Beute).
     *
     * @return array{difficulty: string, reason: string}|null
     */
    public static function suggestion(?int $familyId, string $scope, ?int $userId = null): ?array
    {
        if ($familyId === null) {
            return null;
        }

        $last = Quest::where('family_id', $familyId)->where('scope', $scope)
            ->when($scope === 'solo', fn ($q) => $q->where('user_id', $userId))
            ->whereIn('status', ['completed', 'expired'])
            ->latest('id')->limit(3)->get();

        $closeDefeat = fn (Quest $q) => $q->status === 'expired' && $q->goal > 0 && $q->progress / $q->goal >= (float) config('game.insights.close_defeat');
        if ($last->count() >= 2 && $last->take(2)->every($closeDefeat)) {
            return ['difficulty' => 'easy', 'reason' => 'Zweimal ganz knapp! Mit „leicht“ klappt es bestimmt.'];
        }

        $clearWin = function (Quest $q) {
            if ($q->status !== 'completed' || $q->completed_at === null || $q->created_at === null) {
                return false;
            }
            $planned = max(1, $q->created_at->diffInSeconds($q->ends_at));

            return $q->created_at->diffInSeconds($q->completed_at) <= $planned * (float) config('game.insights.clear_win');
        };
        if ($last->count() === 3 && $last->every($clearWin)) {
            return ['difficulty' => 'hard', 'reason' => 'Dreimal klar gewonnen! Traut ihr euch an „schwer“? Dafür gibt es mehr Beute.'];
        }

        return null;
    }

    /** Anteil der Sammel-Einheiten in den Kategorien der Quest (letzte 14 Tage), mindestens 20 %. */
    private function categoryShare(Collection $participants, array $categories): float
    {
        if ($categories === []) {
            return 1.0;
        }

        $anchor = $participants->first();
        $to = $anchor->gameDate();
        $completions = ActivityScores::completions($participants->pluck('id'), ActivityScores::utcStart($anchor, $to->subDays(ActivityScores::window())), ActivityScores::utcStart($anchor, $to));
        $total = $completions->sum(fn ($c) => ActivityScores::units($c));

        if ($total < 10) {
            // Zu wenig Daten: gleichmäßige Verteilung über alle Kategorien annehmen
            $share = count($categories) / max(1, count(config('game.categories')));
        } else {
            $share = $completions->filter(fn ($c) => in_array($c->category, $categories, true))->sum(fn ($c) => ActivityScores::units($c)) / $total;
        }

        return max(0.2, min(1.0, $share));
    }
}
