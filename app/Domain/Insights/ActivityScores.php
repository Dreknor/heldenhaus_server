<?php

namespace App\Domain\Insights;

use App\Domain\Gameplay\Formulas;
use App\Enums\CompletionStatus;
use App\Models\ActivityScore;
use App\Models\DayLog;
use App\Models\TaskCompletion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Aktivitätswert (Konzept §14.6): Tagespunkte je Person nach der Boss-Schaden-Formel
 * (Δ × Schwierigkeit × Faktor, ohne Stärke-Bonus – wie bei den Event-Punkten), dazu Sammel-Einheiten
 * (schwere Aufgaben zählen doppelt) und die Zahl der Aufgaben. Gasthaus-Tage werden markiert und zählen
 * für den Median nicht mit.
 *
 * Befüllt wird nächtlich (heldenhaus:insights) und beim Tageswechsel (RunDayCron). Weil Freigaben
 * noch nachträglich abgelehnt werden können, rechnet jeder Lauf das ganze Fenster neu.
 */
class ActivityScores
{
    /** @var array<int, array{points: float, units: float, own: bool}> */
    private array $values = [];

    /** Tage [from, to] neu berechnen und speichern. */
    public function fill(User $user, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): void
    {
        $to ??= $user->gameDate()->subDay();
        $from ??= $to->subDays(self::window() - 1);
        $joined = $user->gameDate($user->created_at ?? now());
        if ($from->lt($joined)) {
            $from = $joined;
        }
        if ($from->gt($to)) {
            return;
        }

        $days = $this->daily($user, $from, $to);
        foreach ($days as $date => $row) {
            ActivityScore::updateOrCreate(
                ['user_id' => $user->id, 'date' => $date],
                ['family_id' => $user->family_id, ...$row],
            );
        }
        unset($this->values[$user->id]);
    }

    /** Einen Tag nachziehen (Tageswechsel). */
    public function record(User $user, CarbonImmutable $date): void
    {
        $this->fill($user, $date, $date);
    }

    /**
     * Tageswerte aus den Abschlüssen berechnen (ohne zu speichern).
     *
     * @return array<string, array{points: float, units: int, tasks: int, sleeping: bool}>
     */
    public function daily(User $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $completions = self::completions(collect([$user->id]), self::utcStart($user, $from), self::utcStart($user, $to->addDay()));
        $byDay = $completions->groupBy(fn ($c) => $user->gameDate($c->created_at)->toDateString());

        $sleepingLogs = DayLog::where('user_id', $user->id)->where('sleeping', true)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])->pluck('date')
            ->map(fn ($d) => CarbonImmutable::parse($d)->toDateString())->all();
        $stats = $user->stats()->first();
        $sleepingSince = $stats?->sleeping && $stats->sleeping_since ? $user->gameDate($stats->sleeping_since)->toDateString() : null;

        $out = [];
        for ($day = $from; $day->lte($to); $day = $day->addDay()) {
            $date = $day->toDateString();
            $list = $byDay->get($date, collect());
            $out[$date] = [
                'points' => round((float) $list->sum(fn ($c) => self::points($c)), 2),
                'units' => (int) $list->sum(fn ($c) => self::units($c)),
                'tasks' => $list->count(),
                'sleeping' => in_array($date, $sleepingLogs, true) || ($sleepingSince !== null && $date >= $sleepingSince),
            ];
        }

        return $out;
    }

    /**
     * Aktivitätswert einer Person: Median der Tagespunkte der letzten 14 Tage ohne Gasthaus-Tage,
     * nur Tage seit dem Beitritt. Mit weniger als min_days eigenen Tagen gilt der Standardwert der Altersgruppe.
     *
     * @return array{points: float, units: float, own: bool}
     */
    public function value(User $user): array
    {
        if (isset($this->values[$user->id])) {
            return $this->values[$user->id];
        }

        $to = $user->gameDate()->subDay();
        $from = $to->subDays(self::window() - 1);
        $rows = ActivityScore::where('user_id', $user->id)->whereBetween('date', [$from->toDateString(), $to->toDateString()])->get();
        // Fehlende Tage (z. B. vor dem ersten nächtlichen Lauf) direkt aus den Abschlüssen nachrechnen
        if ($rows->count() < self::window()) {
            $this->fill($user, $from, $to);
            $rows = ActivityScore::where('user_id', $user->id)->whereBetween('date', [$from->toDateString(), $to->toDateString()])->get();
        }
        $usable = $rows->where('sleeping', false);

        if ($usable->count() < (int) config('game.insights.min_days')) {
            $group = $user->ageGroup()->value;

            return $this->values[$user->id] = [
                'points' => (float) config("game.insights.default_points.{$group}"),
                'units' => (float) config("game.insights.default_units.{$group}"),
                'own' => false,
            ];
        }

        return $this->values[$user->id] = [
            'points' => round(self::median($usable->pluck('points')->all()), 2),
            'units' => round(self::median($usable->pluck('units')->all()), 2),
            'own' => true,
        ];
    }

    /** Tagespunkte eines Abschlusses nach der Boss-Schaden-Formel. */
    public static function points(object $completion): float
    {
        return Formulas::bossDamage(max(0.0, (float) $completion->delta), (float) ($completion->difficulty ?? 1));
    }

    /** Sammel-Einheiten wie bei Sammel-Quests: schwere Aufgaben bringen 2. */
    public static function units(object $completion): int
    {
        return (float) ($completion->difficulty ?? 1) >= 2 ? 2 : 1;
    }

    /** Positive, nicht abgelehnte oder zurückgenommene Abschlüsse mit Aufgabendaten. */
    public static function completions(Collection $userIds, CarbonImmutable $fromUtc, CarbonImmutable $toUtc): Collection
    {
        return TaskCompletion::query()
            ->join('tasks', 'tasks.id', '=', 'task_completions.task_id')
            ->whereIn('task_completions.user_id', $userIds)
            ->where('task_completions.direction', 'up')
            ->whereIn('task_completions.status', [CompletionStatus::None, CompletionStatus::Pending, CompletionStatus::Approved, CompletionStatus::Auto])
            ->where('task_completions.created_at', '>=', $fromUtc)
            ->where('task_completions.created_at', '<', $toUtc)
            ->get([
                'task_completions.id', 'task_completions.user_id', 'task_completions.task_id', 'task_completions.delta',
                'task_completions.stars', 'task_completions.status', 'task_completions.xp', 'task_completions.created_at',
                'tasks.difficulty', 'tasks.category', 'tasks.title', 'tasks.is_private', 'tasks.scope', 'tasks.type', 'tasks.due_date',
            ]);
    }

    /** Beginn eines Spieltags der Person in UTC (Zeitzone und Tagesbeginn beachtet). */
    public static function utcStart(User $user, CarbonImmutable $gameDay): CarbonImmutable
    {
        return CarbonImmutable::parse($gameDay->toDateString(), $user->timezone ?: 'Europe/Berlin')
            ->addHours($user->day_start ?? 0)->utc();
    }

    public static function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return $n % 2 ? (float) $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }

    public static function window(): int
    {
        return (int) config('game.insights.window_days', 14);
    }
}
