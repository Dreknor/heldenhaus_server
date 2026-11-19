<?php

namespace App\Domain\Insights;

use App\Enums\CompletionStatus;
use App\Enums\FamilyRole;
use App\Models\DayLog;
use App\Models\Family;
use App\Models\FamilyMetric;
use App\Models\RewardRedemption;
use App\Models\Survey;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Kennzahlen je Familie (Konzept §14.10), nächtlich je Tag zusammengefasst (family_metrics) und für
 * die Eltern-Ansicht „So läuft's“ über 14 Tage ausgewertet. Alles bleibt in der eigenen Datenbank.
 */
class FamilyMetrics
{
    public const WINDOW = 14;

    public const BREAK_WINDOW = 90;

    public function __construct(private readonly ActivityScores $scores) {}

    /** @return Collection<int, User> */
    public static function members(Family $family): Collection
    {
        return User::with('stats')->where('family_id', $family->id)->where('family_role', '!=', FamilyRole::Guest->value)->orderBy('name')->get();
    }

    public static function today(Family $family): CarbonImmutable
    {
        return CarbonImmutable::now($family->timezone ?: 'Europe/Berlin')->startOfDay();
    }

    /**
     * Fehlende Tage [from, to] berechnen und speichern (heute nie, der Tag läuft noch).
     *
     * @return Collection<string, array> Datum → Tageswerte je Mitglied
     */
    public function ensure(Family $family, CarbonImmutable $from, CarbonImmutable $to, bool $refresh = false): Collection
    {
        $today = self::today($family);
        if ($to->gte($today)) {
            $to = $today->subDay();
        }
        $rows = FamilyMetric::where('family_id', $family->id)->whereBetween('date', [$from->toDateString(), $to->toDateString()])->get()
            ->keyBy(fn (FamilyMetric $m) => $m->date->toDateString());

        $missing = [];
        for ($d = $from; $d->lte($to); $d = $d->addDay()) {
            if ($refresh || ! $rows->has($d->toDateString())) {
                $missing[] = $d;
            }
        }
        if ($missing !== []) {
            $computed = $this->compute($family, $missing[0], end($missing));
            foreach ($missing as $d) {
                $date = $d->toDateString();
                $rows[$date] = FamilyMetric::updateOrCreate(['family_id' => $family->id, 'date' => $date], ['data' => $computed[$date] ?? ['members' => []]]);
            }
        }

        return $rows->sortKeys()->map(fn (FamilyMetric $m) => $m->data);
    }

    /**
     * Tageswerte aller Mitglieder für einen Zeitraum.
     *
     * @return array<string, array{members: array<int, array>}>
     */
    public function compute(Family $family, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $out = [];
        foreach (self::members($family) as $member) {
            $days = $this->scores->daily($member, $from, $to);
            $completions = ActivityScores::completions(collect([$member->id]), ActivityScores::utcStart($member, $from), ActivityScores::utcStart($member, $to->addDay()))
                ->groupBy(fn ($c) => $member->gameDate($c->created_at)->toDateString());
            $logs = DayLog::where('user_id', $member->id)->whereBetween('date', [$from->toDateString(), $to->toDateString()])->get()
                ->keyBy(fn (DayLog $l) => $l->date->toDateString());
            $redeemed = RewardRedemption::where('user_id', $member->id)->where('status', '!=', 'rejected')
                ->whereBetween('created_at', [ActivityScores::utcStart($member, $from), ActivityScores::utcStart($member, $to->addDay())])->get()
                ->groupBy(fn ($r) => $member->gameDate($r->created_at)->toDateString());
            $mastered = GoalData::mastered($member);

            foreach ($days as $date => $day) {
                $list = $completions->get($date, collect());
                $familyTasks = $list->where('scope', 'family');
                $withDue = $familyTasks->filter(fn ($c) => $c->due_date !== null);
                $log = $logs->get($date);
                $out[$date]['members'][$member->id] = [
                    'active' => $day['tasks'] > 0,
                    'tasks' => $day['tasks'],
                    'points' => $day['points'],
                    'sleeping' => $day['sleeping'],
                    'dailies_due' => (int) ($log->dailies_due ?? 0),
                    'dailies_done' => (int) ($log->dailies_done ?? 0),
                    'family_done' => $familyTasks->count(),
                    'family_with_due' => $withDue->count(),
                    // „ohne Nachfrage“: Familienaufgabe mit Fälligkeit, erledigt bis zum Fälligkeitstag
                    'family_on_time' => $withDue->filter(fn ($c) => $date <= CarbonImmutable::parse($c->due_date)->toDateString())->count(),
                    'stars_earned' => (int) $list->filter(fn ($c) => in_array($c->status, [CompletionStatus::Approved, CompletionStatus::Auto], true))->sum('stars'),
                    'stars_redeemed' => (int) $redeemed->get($date, collect())->sum('stars'),
                    'goals_reached' => count(GoalData::reached($member, CarbonImmutable::parse($date), CarbonImmutable::parse($date))),
                    'mastered' => $mastered,
                ];
            }
        }

        return $out;
    }

    /** Kennzahlen der letzten 14 Tage für „So läuft's“. */
    public function summary(Family $family): array
    {
        $to = self::today($family)->subDay();
        $from = $to->subDays(self::WINDOW - 1);
        $rows = $this->ensure($family, $from, $to);
        $members = self::members($family);

        $perMember = $members->map(function (User $m) use ($rows) {
            $days = $rows->map(fn ($r) => $r['members'][$m->id] ?? $r['members'][(string) $m->id] ?? null)->filter();

            return [
                'id' => $m->id,
                'name' => $m->name,
                'avatar_color' => $m->avatar_color,
                'role' => $m->family_role->value,
                'age_group' => $m->age_group,
                ...self::aggregate($days),
                'longest_break' => $this->longestBreak($m),
            ];
        })->values();

        $all = $rows->flatMap(fn ($r) => array_values($r['members'] ?? []));

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'members' => $perMember->all(),
            'family' => [
                ...self::aggregate($all),
                'active_days_per_week' => $perMember->isEmpty() ? 0 : round($perMember->avg('active_days_per_week'), 1),
            ],
            'goals_supported' => GoalData::available(),
            'mastery_supported' => GoalData::hasMasteredColumn(),
        ];
    }

    /** Vorher-Nachher: Ausgangslage-Fragen (erste vs. letzte Runde) und Kennzahlen (erste vs. letzte 14 Tage). */
    public function compare(Family $family): array
    {
        $baseline = Survey::where('family_id', $family->id)->where('kind', 'baseline')->whereNotNull('answers')->orderBy('date')->get();
        $firstRound = $baseline->groupBy('user_id')->map->first();
        $lastRound = $baseline->groupBy('user_id')->map->last();
        $avg = fn (Collection $rows, string $key) => $rows->isEmpty() ? null : round($rows->avg(fn ($s) => $s->answers[$key] ?? 0), 1);

        $questions = collect(SurveyService::BASELINE)->map(fn ($q, $key) => [
            'key' => $key,
            'text' => $q['text'],
            'better' => $q['better'],
            'before' => $avg($firstRound, $key),
            'after' => $baseline->count() > $firstRound->count() ? $avg($lastRound, $key) : null,
        ])->values()->all();

        $firstMetric = FamilyMetric::where('family_id', $family->id)->min('date');
        $metrics = null;
        if ($firstMetric) {
            $start = CarbonImmutable::parse(substr((string) $firstMetric, 0, 10), $family->timezone ?: 'Europe/Berlin');
            $before = FamilyMetric::where('family_id', $family->id)->whereBetween('date', [$start->toDateString(), $start->addDays(self::WINDOW - 1)->toDateString()])->get();
            $to = self::today($family)->subDay();
            $afterFrom = $to->subDays(self::WINDOW - 1);
            // Nur vergleichen, wenn sich die Zeiträume nicht überschneiden
            if ($afterFrom->gt($start->addDays(self::WINDOW - 1))) {
                $after = $this->ensure($family, $afterFrom, $to);
                $metrics = [
                    'before' => ['from' => $start->toDateString(), ...self::aggregate($before->flatMap(fn ($m) => array_values($m->data['members'] ?? [])))],
                    'after' => ['from' => $afterFrom->toDateString(), ...self::aggregate($after->flatMap(fn ($r) => array_values($r['members'] ?? [])))],
                ];
            }
        }

        return [
            'baseline' => [
                'rounds' => $baseline->count(),
                'first_date' => $baseline->first()?->date?->toDateString(),
                'last_date' => $baseline->count() > $firstRound->count() ? $baseline->last()?->date?->toDateString() : null,
                'questions' => $questions,
            ],
            'metrics' => $metrics,
        ];
    }

    /**
     * Aus Tageswerten zusammenfassen (mehrere Mitglieder → Summe, Wochenschnitt je Mitglied-Tag).
     *
     * @param  Collection<int, array>  $days
     */
    public static function aggregate(Collection $days): array
    {
        $memberDays = max(1, $days->count());
        $due = (int) $days->sum('dailies_due');
        $withDue = (int) $days->sum('family_with_due');
        $mastered = $days->pluck('mastered')->filter(fn ($v) => $v !== null);

        return [
            'active_days_per_week' => round($days->where('active', true)->count() / $memberDays * 7, 1),
            'dailies_rate' => $due > 0 ? round($days->sum('dailies_done') / $due, 2) : null,
            'family_tasks' => [
                'done' => (int) $days->sum('family_done'),
                'with_due' => $withDue,
                'on_time' => (int) $days->sum('family_on_time'),
                'on_time_rate' => $withDue > 0 ? round($days->sum('family_on_time') / $withDue, 2) : null,
            ],
            'mastered' => $mastered->isEmpty() ? null : (int) $mastered->last(),
            'goals_reached' => (int) $days->sum('goals_reached'),
            'stars' => ['earned' => (int) $days->sum('stars_earned'), 'redeemed' => (int) $days->sum('stars_redeemed')],
        ];
    }

    /**
     * Längste Pause der letzten 90 Tage (Tage ohne Aufgabe, Gasthaus zählt nicht als Pause) und ob es danach weiterging.
     *
     * @return array{days: int, returned: bool}
     */
    public function longestBreak(User $user): array
    {
        $to = $user->gameDate()->subDay();
        $from = $to->subDays(self::BREAK_WINDOW - 1);
        $joined = $user->gameDate($user->created_at ?? now());
        if ($from->lt($joined)) {
            $from = $joined;
        }
        if ($from->gt($to)) {
            return ['days' => 0, 'returned' => false];
        }

        $longest = 0;
        $current = 0;
        $returned = false;
        foreach ($this->scores->daily($user, $from, $to) as $day) {
            if ($day['tasks'] === 0 && ! $day['sleeping']) {
                $current++;
                if ($current > $longest) {
                    $longest = $current;
                    $returned = false;
                }
            } else {
                if ($current > 0 && $current === $longest && $day['tasks'] > 0) {
                    $returned = true;
                }
                $current = 0;
            }
        }

        return ['days' => $longest, 'returned' => $returned];
    }
}
