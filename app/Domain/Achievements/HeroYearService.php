<?php

namespace App\Domain\Achievements;

use App\Domain\Badges\BadgeService;
use App\Domain\Events\EventCalendar;
use App\Models\Family;
use App\Models\FamilyEventSetting;
use App\Models\HeroYear;
use App\Models\HolidayPeriod;
use App\Models\Item;
use App\Models\TaskCompletion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Heldenjahr (§14.7): eine kostenlose Jahresleiste mit 50 Stufen, die zum Schuljahresbeginn neu startet.
 * Schuljahresbeginn = Tag nach dem Ende der Sommerferien im Bundesland der Familie (Schulferien aus §12.3,
 * auch von Hand eingetragene), sonst der 1. August. Jahrespunkte gibt es für Aufgaben (mit Tageslimit),
 * Events und Ziele. Es gibt keine Bezahl-Stufe.
 */
class HeroYearService
{
    public const SOURCES = ['task', 'event', 'goal', 'other'];

    public function __construct(private readonly BadgeService $badges) {}

    /**
     * Schuljahr, in dem ein Tag liegt: [Jahr des Beginns, erster Tag, letzter Tag].
     *
     * @return array{0: int, 1: CarbonImmutable, 2: CarbonImmutable}
     */
    public static function period(?Family $family, CarbonImmutable $date): array
    {
        // Als Kalendertag vergleichen – die Spielzeit der Person kann in einer anderen Zeitzone liegen
        $day = $date->toDateString();
        $year = (int) $date->year;
        $start = self::schoolYearStart($family, $year);
        if ($day < $start->toDateString()) {
            $year--;
            $start = self::schoolYearStart($family, $year);
        }

        return [$year, $start, self::schoolYearStart($family, $year + 1)->subDay()];
    }

    /** Erster Schultag nach den Sommerferien eines Jahres (Bundesland der Familie), sonst der 1. August. */
    public static function schoolYearStart(?Family $family, int $year): CarbonImmutable
    {
        $settings = $family ? FamilyEventSetting::for($family->id) : null;
        $country = $settings->country ?? 'DE';
        $subdivision = $settings->subdivision ?? null;

        $summer = HolidayPeriod::where('type', 'school')
            ->where(function ($q) use ($country, $subdivision, $family) {
                $q->where(fn ($q) => $q->where('country', $country)->where('subdivision', $subdivision)->whereNull('family_id'));
                if ($family) {
                    $q->orWhere('family_id', $family->id);
                }
            })
            ->whereYear('ends_on', $year)
            ->orderByRaw('family_id is null')
            ->get()
            ->first(fn (HolidayPeriod $p) => EventCalendar::holidayKind($p->name) === 'summer');

        if ($summer !== null) {
            return CarbonImmutable::parse($summer->ends_on->toDateString())->addDay();
        }

        return CarbonImmutable::createFromFormat('!Y-m-d', $year.'-'.LongTermCatalog::heroYear()['fallback_start']);
    }

    /** Heldenjahr-Zeile des laufenden Schuljahrs (legt sie beim ersten Zugriff an – das ist der Neustart). */
    public function current(User $user, ?CarbonImmutable $date = null): HeroYear
    {
        [$year, $start, $end] = self::period($user->family, $date ?? $user->gameDate());

        $row = HeroYear::firstOrCreate(
            ['user_id' => $user->id, 'start_year' => $year],
            ['starts_on' => $start->toDateString(), 'ends_on' => $end->toDateString()],
        );

        // Ferien können später nachgeladen oder von Hand korrigiert werden
        if ($row->starts_on->toDateString() !== $start->toDateString() || $row->ends_on->toDateString() !== $end->toDateString()) {
            $row->update(['starts_on' => $start->toDateString(), 'ends_on' => $end->toDateString()]);
        }

        return $row;
    }

    public static function pointsForDifficulty(float $difficulty): int
    {
        $p = LongTermCatalog::heroYear()['task_points'];

        return match (true) {
            $difficulty <= 0.1 => $p['trivial'],
            $difficulty <= 1.0 => $p['easy'],
            $difficulty <= 1.5 => $p['medium'],
            default => $p['hard'],
        };
    }

    /** Jahrespunkte für einen Abschluss; zählt er für ein laufendes Event, gibt es einen Event-Bonus. */
    public static function pointsForCompletion(TaskCompletion $completion): array
    {
        $completion->loadMissing('task');

        return [
            'task' => self::pointsForDifficulty((float) ($completion->task?->difficulty ?? 1)),
            'event' => empty($completion->event_progress) ? 0 : (int) LongTermCatalog::heroYear()['event_bonus'],
        ];
    }

    public function awardCompletion(TaskCompletion $completion): int
    {
        $user = $completion->user ?? User::find($completion->user_id);
        if ($user === null) {
            return 0;
        }
        $points = self::pointsForCompletion($completion);

        return $this->addPoints($user, $points['task'], 'task') + $this->addPoints($user, $points['event'], 'event');
    }

    /**
     * Jahrespunkte gutschreiben (auch für Ziele und Events aus anderen Modulen). Aufgaben-Punkte haben ein
     * Tageslimit, damit Dauerklicken nichts bringt. Neue Stufen werden sofort und genau einmal belohnt.
     *
     * @return int tatsächlich gutgeschriebene Punkte
     */
    public function addPoints(User $user, int $points, string $source): int
    {
        if ($points <= 0) {
            return 0;
        }

        $cfg = LongTermCatalog::heroYear();
        $today = $user->gameDate();
        $rowId = $this->current($user, $today)->id;

        return DB::transaction(function () use ($user, $points, $source, $cfg, $today, $rowId) {
            $row = HeroYear::lockForUpdate()->findOrFail($rowId);

            if ($source === 'task') {
                if ($row->points_on?->toDateString() !== $today->toDateString()) {
                    $row->points_today = 0;
                    $row->points_on = $today->toDateString();
                }
                $points = max(0, min($points, (int) $cfg['daily_task_cap'] - $row->points_today));
                $row->points_today += $points;
            }

            $row->points += $points;
            $row->level = min((int) $cfg['levels'], intdiv($row->points, (int) $cfg['points_per_level']));

            for ($level = $row->rewarded_level + 1; $level <= $row->level; $level++) {
                $this->reward($user, $row, $level);
            }
            $row->rewarded_level = max($row->rewarded_level, $row->level);
            $row->save();

            return $points;
        });
    }

    /** Belohnung einer Stufe: Gold, ggf. Möbel oder Kosmetik, auf Stufe 50 das Jahres-Abzeichen. */
    private function reward(User $user, HeroYear $row, int $level): void
    {
        $reward = self::rewardFor($level);
        LongTermRewards::grant($user, $reward);

        if ($reward['badge'] ?? false) {
            $label = self::label($row->start_year);
            $this->badges->award($user, "hero_year:{$row->start_year}", 'year', "Heldenjahr {$label}", '🏅',
                ['start_year' => $row->start_year], "{$user->name} hat das Heldenjahr {$label} geschafft – alle 50 Stufen!");
        }
    }

    public static function rewardFor(int $level): array
    {
        $cfg = LongTermCatalog::heroYear();

        return [...$cfg['default_reward'], ...($cfg['rewards'][(string) $level] ?? [])];
    }

    /** „2026/27“ */
    public static function label(int $startYear): string
    {
        return $startYear.'/'.substr((string) ($startYear + 1), -2);
    }

    public function present(User $user): array
    {
        $cfg = LongTermCatalog::heroYear();
        $row = $this->current($user);
        $today = $user->gameDate();
        $per = (int) $cfg['points_per_level'];
        $max = (int) $cfg['levels'];
        $items = Item::whereIn('key', collect($cfg['rewards'])->pluck('item')->filter()->all())->get()->keyBy('key');

        $days = max(1, $row->starts_on->diffInDays($row->ends_on) + 1);
        $elapsed = (int) min($days, max(0, $row->starts_on->diffInDays($today) + 1));

        return [
            'label' => self::label($row->start_year),
            'starts_on' => $row->starts_on->toDateString(),
            'ends_on' => $row->ends_on->toDateString(),
            'restart_on' => $row->ends_on->addDay()->toDateString(),
            'days_left' => max(0, $days - $elapsed),
            'year_progress' => round($elapsed / $days, 3),
            'points' => $row->points,
            'level' => $row->level,
            'max_level' => $max,
            'points_per_level' => $per,
            'level_points' => $row->level >= $max ? $per : $row->points - $row->level * $per,
            'points_today' => $row->points_on?->toDateString() === $today->toDateString() ? $row->points_today : 0,
            'daily_task_cap' => (int) $cfg['daily_task_cap'],
            'task_points' => $cfg['task_points'],
            'stages' => collect(range(1, $max))->map(function (int $level) use ($row, $items) {
                $reward = self::rewardFor($level);
                $item = isset($reward['item']) ? $items->get($reward['item']) : null;

                return [
                    'level' => $level,
                    'reached' => $row->level >= $level,
                    'gold' => (float) ($reward['gold'] ?? 0),
                    'item' => $item?->only(['key', 'name', 'icon', 'type']),
                    'badge' => (bool) ($reward['badge'] ?? false),
                ];
            })->all(),
            'history' => HeroYear::where('user_id', $user->id)->where('start_year', '<', $row->start_year)->orderByDesc('start_year')->get()
                ->map(fn (HeroYear $y) => ['label' => self::label($y->start_year), 'level' => $y->level, 'points' => $y->points])->all(),
        ];
    }
}
