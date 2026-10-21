<?php

namespace App\Domain\Support;

use App\Enums\AgeGroup;
use App\Models\AiRequest;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * KI-Aufteilung über OpenRouter (§11.6). Nur Titel, Notiz, Altersgruppe und Schrittzahl gehen raus –
 * keine Namen, keine Familien- oder Gesundheitsdaten. Anbieter mit Datenspeicherung sind ausgeschlossen.
 */
class OpenRouterClient
{
    private const SYSTEM = 'Du hilfst Kindern, Jugendlichen und Erwachsenen, eine Aufgabe in kleine Schritte zu zerlegen. '
        .'Antworte nur mit JSON: {"steps": ["…"]}. Jeder Schritt beginnt mit einem Verb, ist höchstens 8 Wörter lang, '
        .'konkret und sicher (keine Chemikalien, kein Strom, keine Messer bei Kindern). Sprache: Deutsch.';

    private const ADULT_SYSTEM = 'Du hilfst Erwachsenen, eine Aufgabe in kleine Schritte zu zerlegen. '
        .'Antworte nur mit JSON: {"steps": ["…"]}. Jeder Schritt beginnt mit einem Verb, ist höchstens 8 Wörter lang '
        .'und konkret umsetzbar. Übliche Werkzeuge sind erlaubt; weise bei erkennbaren Risiken auf Schutzmaßnahmen hin. '
        .'Gib keine Anleitung zu illegalen oder hochriskanten Tätigkeiten, die Fachkenntnis erfordern, etwa Arbeiten '
        .'an Netzspannung, Gas oder gefährlichen Chemikalien. Sprache: Deutsch.';

    public static function configured(): bool
    {
        return (bool) config('services.openrouter.key');
    }

    /** @return list<string>|null */
    public function steps(User $user, string $title, ?string $notes, int $maxSteps): ?array
    {
        $prompt = json_encode(array_filter([
            'aufgabe' => mb_substr($title, 0, 120),
            'notiz' => $notes ? mb_substr($notes, 0, 300) : null,
            'altersgruppe' => $user->ageGroup()->value,
            'schritte_hoechstens' => $maxSteps,
        ]), JSON_UNESCAPED_UNICODE);

        $model = config('services.openrouter.model');
        $usage = ['prompt_tokens' => 0, 'completion_tokens' => 0];
        $cost = 0.0;
        $steps = null;

        try {
            $response = Http::withToken(config('services.openrouter.key'))->timeout(20)
                ->withHeaders(['X-Title' => 'Heldenhaus'])
                ->post('https://openrouter.ai/api/v1/chat/completions', [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => $user->ageGroup() === AgeGroup::Adult ? self::ADULT_SYSTEM : self::SYSTEM],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'response_format' => ['type' => 'json_object'],
                    'max_tokens' => 300,
                    'temperature' => 0.3,
                    'provider' => ['data_collection' => 'deny'],
                    'usage' => ['include' => true],
                ]);

            $usage = array_merge($usage, (array) $response->json('usage'));
            $cost = (float) ($response->json('usage.cost') ?? 0);
            $steps = $response->successful() ? self::parse((string) $response->json('choices.0.message.content'), $maxSteps) : null;
        } catch (Throwable) {
            $steps = null;
        }

        AiRequest::create([
            'family_id' => $user->family_id,
            'user_id' => $user->id,
            'purpose' => 'steps',
            'model' => $model,
            'prompt_tokens' => (int) $usage['prompt_tokens'],
            'completion_tokens' => (int) $usage['completion_tokens'],
            'cost_usd' => $cost,
            'ok' => $steps !== null,
        ]);

        return $steps;
    }

    /** Antwort prüfen: JSON mit 2..max kurzen Schritten, sonst verwerfen. */
    public static function parse(string $content, int $maxSteps): ?array
    {
        $json = json_decode(trim(preg_replace('/^```(?:json)?|```$/m', '', $content)), true);
        $steps = is_array($json) ? ($json['steps'] ?? null) : null;
        if (! is_array($steps)) {
            return null;
        }

        $steps = array_values(array_filter(array_map(
            fn ($s) => is_string($s) ? mb_substr(trim(strip_tags($s)), 0, 80) : '',
            $steps,
        )));

        return count($steps) >= 2 ? array_slice($steps, 0, $maxSteps) : null;
    }
}
