<?php

namespace App\Domain\Events;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Optionales Wetter von Open-Meteo (kostenlos, ohne Schlüssel) für einen auf 0,1° gerundeten Ort (§7),
 * nur wenn die Eltern es einschalten. Liefert Signale wie bei Home Assistant: weather_snow, weather_hot.
 */
class WeatherSource
{
    /** @return list<string> */
    public function signals(float $lat, float $lon, string $timezone): array
    {
        try {
            $response = Http::timeout(10)->acceptJson()->get(config('game.events.open_meteo_url'), [
                'latitude' => round($lat, 1),
                'longitude' => round($lon, 1),
                'daily' => 'temperature_2m_max,snowfall_sum',
                'timezone' => $timezone,
                'past_days' => 1,
                'forecast_days' => 1,
            ]);
        } catch (\Throwable $e) {
            Log::info("Open-Meteo nicht erreichbar: {$e->getMessage()}");

            return [];
        }
        if (! $response->successful()) {
            return [];
        }

        $daily = $response->json('daily') ?? [];
        $snow = array_sum(array_map('floatval', $daily['snowfall_sum'] ?? []));
        $temps = array_map('floatval', $daily['temperature_2m_max'] ?? []);
        $minTemp = (float) (EventCatalog::get('heatwave')['trigger']['min_temp'] ?? 30);

        return array_values(array_filter([
            $snow > 0 ? 'weather_snow' : null,
            count($temps) >= 2 && min($temps) >= $minTemp ? 'weather_hot' : null,
        ]));
    }
}
