<?php

namespace App\Domain\Events;

use App\Models\HolidayPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Feiertage und Schulferien aus der kostenlosen OpenHolidays API (openholidaysapi.org, ohne Schlüssel).
 * Einmal im Monat für 12 Monate im Voraus geladen; fällt die API aus, bleiben die bisherigen
 * und die von Hand eingetragenen Zeiträume gültig.
 */
class HolidaySource
{
    /** @return int Anzahl gespeicherter Zeiträume, -1 bei Fehler */
    public function sync(string $country, ?string $subdivision, ?CarbonImmutable $from = null, int $months = 12): int
    {
        $from ??= CarbonImmutable::now()->startOfMonth()->subMonths(2);
        $to = $from->addMonths($months + 2);
        $count = 0;

        foreach (['school' => 'SchoolHolidays', 'public' => 'PublicHolidays'] as $type => $endpoint) {
            try {
                $response = Http::timeout(15)->acceptJson()->get(rtrim(config('game.events.openholidays_url'), '/')."/{$endpoint}", array_filter([
                    'countryIsoCode' => $country,
                    'subdivisionCode' => $subdivision,
                    'languageIsoCode' => 'DE',
                    'validFrom' => $from->toDateString(),
                    'validTo' => $to->toDateString(),
                ]));
            } catch (\Throwable $e) {
                Log::warning("OpenHolidays nicht erreichbar: {$e->getMessage()}");

                return -1;
            }
            if (! $response->successful() || ! is_array($response->json())) {
                Log::warning("OpenHolidays antwortet mit {$response->status()}");

                return -1;
            }

            foreach ($response->json() as $row) {
                $name = collect($row['name'] ?? [])->firstWhere('language', 'DE')['text'] ?? ($row['name'][0]['text'] ?? 'Ferien');
                $period = HolidayPeriod::where(['country' => $country, 'subdivision' => $subdivision, 'type' => $type])
                    ->whereNull('family_id')->whereDate('starts_on', $row['startDate'])->first() ?? new HolidayPeriod;
                $period->fill([
                    'country' => $country, 'subdivision' => $subdivision, 'type' => $type, 'name' => $name,
                    'starts_on' => $row['startDate'], 'ends_on' => $row['endDate'], 'source' => 'openholidays', 'fetched_at' => now(),
                ])->save();
                $count++;
            }
        }

        return $count;
    }
}
