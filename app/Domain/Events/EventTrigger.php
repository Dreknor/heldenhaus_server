<?php

namespace App\Domain\Events;

use App\Domain\Classes\ClassService;
use App\Domain\Features\Features;
use App\Models\Family;
use App\Models\FamilyEvent;
use App\Models\FamilyEventSetting;
use Carbon\CarbonImmutable;

/**
 * Ereignisgesteuerte Events (§12.3): prüft Abschaltung, Bedingungen, Abklingzeit, Dubletten und das
 * Limit gleichzeitiger Events, plant die Instanz und startet sie (oder reiht sie ein).
 * Jede Entscheidung landet im Prüfprotokoll event_trigger_log.
 */
class EventTrigger
{
    public function __construct(private readonly EventEngine $engine) {}

    /**
     * @param  array{subject_user_id?: int, source?: string, starts_at?: CarbonImmutable, ends_at?: CarbonImmutable, manual?: bool}  $context
     */
    public function consider(int $familyId, string $key, array $context = []): ?FamilyEvent
    {
        $def = EventCatalog::get($key);
        $source = $context['source'] ?? ($def['trigger']['type'] ?? 'manual');
        $manual = (bool) ($context['manual'] ?? false);
        $log = fn (string $result) => EventEngine::log($familyId, $key, $source, $result, array_filter(['subject_user_id' => $context['subject_user_id'] ?? null]));

        if ($def === null) {
            $log('unknown');

            return null;
        }
        if (! $manual && ! FamilyEventSetting::for($familyId)->enabled($key)) {
            $log('disabled');

            return null;
        }
        if (! $manual && ! $this->conditionsMet($familyId, $def)) {
            $log('conditions');

            return null;
        }

        $subject = $context['subject_user_id'] ?? null;
        $same = FamilyEvent::where('family_id', $familyId)->where('event_key', $key)
            ->when($def['personal'] ?? false, fn ($q) => $q->where('subject_user_id', $subject));

        if ((clone $same)->whereIn('status', ['scheduled', 'active', 'ending'])->exists()) {
            $log('duplicate');

            return null;
        }
        if (! $manual && ($def['cooldown_days'] ?? 0) > 0
            && (clone $same)->whereIn('status', ['active', 'ending', 'finished'])->where('starts_at', '>=', now()->subDays($def['cooldown_days']))->exists()) {
            $log('cooldown');

            return null;
        }

        $family = Family::find($familyId);
        $starts = $context['starts_at'] ?? CarbonImmutable::now();
        $ends = $context['ends_at'] ?? self::endOf($def, $starts, $family?->timezone);

        $event = FamilyEvent::create([
            'family_id' => $familyId,
            'subject_user_id' => $subject,
            'event_key' => $key,
            'status' => 'scheduled',
            'starts_at' => $starts,
            'ends_at' => $ends,
            'trigger_source' => $manual ? 'manual' : $source,
            'seed' => substr(sha1("{$familyId}|{$key}|{$subject}|".$starts->toIso8601String()), 0, 16),
        ]);

        // Geplante Kalender-Events startet der Scheduler; alles andere sofort (oder in die Warteschlange)
        if ($starts->gt(now())) {
            $log('scheduled');

            return $event;
        }
        $result = $this->engine->tryActivate($event);

        return $result === 'skipped' ? null : $event->refresh();
    }

    /** Signal aus Home Assistant oder Open-Meteo (weather_snow, weather_hot): passende Welt-Events prüfen. */
    public function signal(int $familyId, string $signal, string $source): array
    {
        $month = (int) CarbonImmutable::now(Family::find($familyId)?->timezone ?? 'Europe/Berlin')->month;

        return collect(EventCatalog::all())
            ->filter(fn ($e) => ($e['trigger']['type'] ?? null) === 'weather' && $e['trigger']['signal'] === $signal)
            ->filter(fn ($e) => ! isset($e['trigger']['months']) || in_array($month, $e['trigger']['months'], true))
            ->map(fn ($e) => $this->consider($familyId, $e['key'], ['source' => $source]))
            ->filter()->map->event_key->values()->all();
    }

    /** Bedingungen: Freischaltung (§13), Home Assistant vorhanden. */
    public function conditionsMet(int $familyId, array $def): bool
    {
        if (! empty($def['requires_feature']) && ! Features::familyHas($familyId, $def['requires_feature'])) {
            return false;
        }

        return ! ($def['requires_ha'] ?? false) || ClassService::familyHasHomeAssistant($familyId);
    }

    /** Ende = Beginn + Dauer; ganztägige Anlässe enden um Mitternacht der Familie. */
    public static function endOf(array $def, CarbonImmutable $starts, ?string $timezone): CarbonImmutable
    {
        $days = max(1, (int) ($def['duration_days'] ?? 1));
        if (in_array($def['trigger']['type'] ?? null, ['family_date'], true)) {
            return $starts->setTimezone($timezone ?? 'Europe/Berlin')->startOfDay()->addDays($days)->utc();
        }

        return $starts->addDays($days);
    }
}
