<?php

namespace App\Domain\Events;

use App\Enums\FamilyRole;
use App\Models\DayLog;
use App\Models\Family;
use App\Models\FamilyEvent;
use App\Models\FamilyEventSetting;
use App\Models\MemberDate;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Täglicher Planer (heldenhaus:events-plan, 00:10): Kalender-Events der nächsten 60 Tage je Familie
 * in ihrer Zeitzone einplanen und die täglichen Auslöser prüfen (Jubiläum, Geburtstag, Goldene Woche,
 * Wandernder Händler, Wetter von Open-Meteo).
 */
class EventPlanner
{
    public function __construct(private readonly EventTrigger $trigger, private readonly WeatherSource $weather) {}

    /** @return array{planned: int, triggered: list<string>} */
    public function plan(Family $family, ?CarbonImmutable $now = null): array
    {
        $tz = $family->timezone ?: 'Europe/Berlin';
        $today = ($now ?? CarbonImmutable::now())->setTimezone($tz)->startOfDay();

        return ['planned' => $this->planCalendar($family, $today), 'triggered' => $this->daily($family, $today)];
    }

    /** Kalender-Events, deren Fenster in den nächsten Tagen beginnt oder gerade läuft. */
    public function planCalendar(Family $family, CarbonImmutable $today): int
    {
        $settings = FamilyEventSetting::for($family->id);
        $horizon = $today->addDays((int) config('game.events.plan_days'));
        $planned = 0;

        foreach (EventCatalog::calendar() as $key => $def) {
            if (! $settings->enabled($key)) {
                continue;
            }
            foreach ([$today->year - 1, $today->year, $today->year + 1] as $year) {
                $window = EventCalendar::window($def, $year, $settings->country, $settings->subdivision, $family->id);
                if ($window === null) {
                    continue;
                }
                [$from, $to] = $window;
                if ($to->lt($today) || $from->gt($horizon)) {
                    continue;
                }

                $starts = CarbonImmutable::parse($from->toDateString(), $today->timezone)->utc();
                $ends = CarbonImmutable::parse($to->addDay()->toDateString(), $today->timezone)->utc();

                // Schon geplant (auch mit leicht anderem Datum, z. B. nach neuen Ferienterminen)?
                $exists = FamilyEvent::where('family_id', $family->id)->where('event_key', $key)
                    ->where('starts_at', '<', $ends)->where('ends_at', '>', $starts)->exists();
                if ($exists) {
                    continue;
                }

                FamilyEvent::create([
                    'family_id' => $family->id,
                    'event_key' => $key,
                    'status' => 'scheduled',
                    'starts_at' => $starts,
                    'ends_at' => $ends,
                    'trigger_source' => 'calendar',
                    'seed' => substr(sha1("{$family->id}|{$key}|{$from->toDateString()}"), 0, 16),
                ]);
                EventEngine::log($family->id, $key, 'calendar', 'scheduled', ['starts_on' => $from->toDateString()]);
                $planned++;
            }
        }

        return $planned;
    }

    /** Nach einem Wechsel des Bundeslands oder neuen Ferien: noch nicht gestartete Kalender-Events neu planen. */
    public function replan(Family $family): int
    {
        FamilyEvent::where('family_id', $family->id)->where('status', 'scheduled')->where('trigger_source', 'calendar')->delete();

        return $this->planCalendar($family, CarbonImmutable::now($family->timezone ?: 'Europe/Berlin')->startOfDay());
    }

    /** @return list<string> ausgelöste Events */
    public function daily(Family $family, CarbonImmutable $today): array
    {
        $started = [];
        $day = ['starts_at' => $today->utc(), 'source' => 'family_date'];

        // Heldenjubiläum: Jahrestag der Familiengründung
        $founded = CarbonImmutable::instance($family->created_at)->setTimezone($today->timezone);
        if ($founded->format('m-d') === $today->format('m-d') && $founded->year < $today->year) {
            $started[] = $this->trigger->consider($family->id, 'hero_anniversary', $day)?->event_key;
        }

        // Geburtstage nur, wenn die Eltern sie eingetragen haben
        MemberDate::where('type', 'birthday')->where('month', $today->month)->where('day', $today->day)
            ->whereIn('user_id', User::where('family_id', $family->id)->select('id'))->get()
            ->each(function (MemberDate $d) use ($family, $day, &$started) {
                $started[] = $this->trigger->consider($family->id, 'birthday', $day + ['subject_user_id' => $d->user_id])?->event_key;
            });

        // Wandernder Händler: ein fester Tag pro Monat und Familie
        $merchantDay = 1 + crc32("merchant|{$family->id}|".$today->format('Y-m')) % $today->daysInMonth;
        if ($today->day === $merchantDay) {
            $started[] = $this->trigger->consider($family->id, 'wandering_merchant', ['source' => 'random'])?->event_key;
        }

        if ($this->allDailiesStreak($family, $today, (int) (EventCatalog::get('golden_week')['trigger']['days'] ?? 3))) {
            $started[] = $this->trigger->consider($family->id, 'golden_week', ['source' => 'game'])?->event_key;
        }

        $settings = FamilyEventSetting::for($family->id);
        if ($settings->weather_source === 'open_meteo' && $settings->weather_lat !== null && $settings->weather_lon !== null) {
            foreach ($this->weather->signals($settings->weather_lat, $settings->weather_lon, $today->timezone->getName()) as $signal) {
                $started = [...$started, ...$this->trigger->signal($family->id, $signal, 'open_meteo')];
            }
        }

        return array_values(array_filter($started));
    }

    /** Haben alle aktiven Mitglieder an den letzten N Tagen alle (Kern-)Dailies geschafft? */
    public function allDailiesStreak(Family $family, CarbonImmutable $today, int $days): bool
    {
        $members = User::where('family_id', $family->id)->where('family_role', '!=', FamilyRole::Guest->value)->pluck('id');
        if ($members->isEmpty()) {
            return false;
        }

        for ($i = 1; $i <= $days; $i++) {
            $date = $today->subDays($i)->toDateString();
            $logs = DayLog::whereIn('user_id', $members)->whereDate('date', $date)->get();
            $active = $logs->reject(fn (DayLog $l) => $l->sleeping || $l->dailies_due === 0);
            if ($active->isEmpty() || $logs->count() < $members->count() || ! $active->every(fn (DayLog $l) => $l->dailies_done >= $l->dailies_due)) {
                return false;
            }
        }

        return true;
    }
}
