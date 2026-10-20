<?php

namespace App\Domain\Events;

use App\Models\HolidayPeriod;
use Carbon\CarbonImmutable;

/**
 * Kalender-Rechner für Events (§12.3): feste Daten, Ostern (Gauß-Osterformel ohne ext-calendar),
 * Regeln (1. Advent, Erntedank), Jahreszeiten und Schulferien des Bundeslands.
 */
class EventCalendar
{
    /** Ostersonntag nach der Gaußschen Osterformel (gregorianisch, in der Form von Lichtenberg). */
    public static function easter(int $year): CarbonImmutable
    {
        $k = intdiv($year, 100);
        $m = 15 + intdiv(3 * $k + 3, 4) - intdiv(8 * $k + 13, 25);
        $s = 2 - intdiv(3 * $k + 3, 4);
        $a = $year % 19;
        $d = (19 * $a + $m) % 30;
        $r = intdiv($d + intdiv($a, 11), 29);
        $og = 21 + $d - $r;
        $sz = 7 - ($year + intdiv($year, 4) + $s) % 7;
        $oe = 7 - ($og - $sz) % 7;

        return CarbonImmutable::create($year, 3, 1)->addDays($og + $oe - 1)->startOfDay();
    }

    /** 1. Advent: vierter Sonntag vor dem 25.12. */
    public static function firstAdvent(int $year): CarbonImmutable
    {
        $christmas = CarbonImmutable::create($year, 12, 25);
        $sunday = $christmas->isSunday() ? $christmas->subWeek() : $christmas->previous(CarbonImmutable::SUNDAY);

        return $sunday->subWeeks(3)->startOfDay();
    }

    /** Erntedank: erster Sonntag im Oktober. */
    public static function harvestThanksgiving(int $year): CarbonImmutable
    {
        $first = CarbonImmutable::create($year, 10, 1);

        return ($first->isSunday() ? $first : $first->next(CarbonImmutable::SUNDAY))->startOfDay();
    }

    public static function rule(string $rule, int $year): CarbonImmutable
    {
        return match ($rule) {
            'first_advent' => self::firstAdvent($year),
            'harvest_thanksgiving' => self::harvestThanksgiving($year),
            default => throw new \InvalidArgumentException("Unbekannte Kalenderregel {$rule}"),
        };
    }

    /**
     * Fenster [Start, Ende] (Tage, inklusive) eines Kalender-Events in einem Jahr, oder null (z. B. ohne bekannte Schulferien).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    public static function window(array $event, int $year, ?string $country = 'DE', ?string $subdivision = 'DE-SN', ?int $familyId = null): ?array
    {
        $t = $event['trigger'];
        $date = fn (string $md, int $y) => CarbonImmutable::createFromFormat('!Y-m-d', "{$y}-{$md}");

        [$from, $to] = match ($t['type']) {
            'fixed' => [$date($t['start'], $year), $date($t['end'], $year)],
            'season' => [$date($t['start'], $year), $date($t['start'], $year)->addDays($event['duration_days'] - 1)],
            'easter_offset' => [self::easter($year)->addDays($t['from']), self::easter($year)->addDays($t['to'])],
            'rule' => [
                isset($t['start_rule']) ? self::rule($t['start_rule'], $year) : $date($t['start'], $year),
                isset($t['end_rule']) ? self::rule($t['end_rule'], $year) : $date($t['end'], $year),
            ],
            'school_holidays' => self::holidayWindow($t, $year, $country, $subdivision, $familyId) ?? [null, null],
            default => [null, null],
        };
        if ($from === null) {
            return null;
        }

        // Über den Jahreswechsel (z. B. Raunächte 27.12.–06.01.)
        return [$from, $to->lt($from) ? $to->addYear() : $to];
    }

    /** Schulferien: Beginn bzw. Ende der passenden Ferien (z. B. „Sommerferien“) im Jahr, mit Versatz in Tagen. */
    private static function holidayWindow(array $t, int $year, ?string $country, ?string $subdivision, ?int $familyId): ?array
    {
        $period = HolidayPeriod::where('type', 'school')
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('country', $country)->where('subdivision', $subdivision)->whereNull('family_id'))
                ->when($familyId, fn ($q) => $q->orWhere('family_id', $familyId)))
            ->whereYear('starts_on', $year)
            ->get()
            ->first(fn (HolidayPeriod $p) => self::holidayKind($p->name) === $t['holiday']);
        if ($period === null) {
            return null;
        }

        $anchor = CarbonImmutable::parse($t['edge'] === 'end' ? $period->ends_on : $period->starts_on);
        $to = $anchor->addDays($t['to']);
        if ($t['edge'] === 'start') {
            $to = $to->min(CarbonImmutable::parse($period->ends_on));
        }

        return [$anchor->addDays($t['from']), $to];
    }

    /** „Sommerferien“ → summer, „Herbstferien“ → autumn … */
    public static function holidayKind(string $name): ?string
    {
        $n = mb_strtolower($name);

        return match (true) {
            str_contains($n, 'sommer') => 'summer',
            str_contains($n, 'herbst') => 'autumn',
            str_contains($n, 'weihnacht') => 'christmas',
            str_contains($n, 'winter') => 'winter',
            str_contains($n, 'oster') => 'easter',
            str_contains($n, 'pfingst') => 'whitsun',
            default => null,
        };
    }
}
