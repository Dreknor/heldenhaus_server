<?php

namespace App\Domain\Events;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Optionales Wetter von Open-Meteo (kostenlos, ohne Schlüssel) für einen auf 0,1° gerundeten Ort (§7),
 * nur wenn die Eltern es einschalten. Liefert aktuelle Szenenbedingungen und Signale wie weather_snow/weather_hot.
 */
class WeatherSource
{
    /** @return array{type: string, label: string, is_day: bool}|null */
    public function current(float $lat, float $lon, string $timezone): ?array
    {
        $key = 'scene-weather:'.sha1(round($lat, 1).'|'.round($lon, 1).'|'.$timezone);
        $weather = Cache::remember($key, now()->addMinutes(15), function () use ($lat, $lon, $timezone): array {
            try {
                $response = Http::timeout(10)->acceptJson()->get(config('game.events.open_meteo_url'), [
                    'latitude' => round($lat, 1),
                    'longitude' => round($lon, 1),
                    'current' => 'weather_code,is_day',
                    'timezone' => $timezone,
                ]);
            } catch (\Throwable $e) {
                Log::info("Open-Meteo nicht erreichbar: {$e->getMessage()}");

                return ['type' => 'unavailable', 'label' => '', 'is_day' => false];
            }
            if (! $response->successful()) {
                return ['type' => 'unavailable', 'label' => '', 'is_day' => false];
            }

            $current = $response->json('current');
            if (! is_array($current) || ! is_numeric($current['weather_code'] ?? null)) {
                return ['type' => 'unavailable', 'label' => '', 'is_day' => false];
            }

            $code = (int) $current['weather_code'];
            $type = match (true) {
                $code === 0 => 'clear',
                in_array($code, [1, 2, 3], true) => 'cloudy',
                in_array($code, [45, 48], true) => 'fog',
                $code >= 51 && $code <= 67, $code >= 80 && $code <= 82 => 'rain',
                in_array($code, [71, 73, 75, 77, 85, 86], true) => 'snow',
                $code >= 95 && $code <= 99 => 'storm',
                default => 'unavailable',
            };

            return [
                'type' => $type,
                'label' => [
                    'clear' => (int) ($current['is_day'] ?? 1) === 1 ? 'Sonnig' : 'Klar',
                    'cloudy' => 'Bewölkt',
                    'fog' => 'Neblig',
                    'rain' => 'Regen',
                    'snow' => 'Schnee',
                    'storm' => 'Gewitter',
                    'unavailable' => '',
                ][$type],
                'is_day' => (int) ($current['is_day'] ?? 1) === 1,
            ];
        });

        return $weather['type'] === 'unavailable' ? null : $weather;
    }

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
