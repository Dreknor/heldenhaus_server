<?php

namespace App\Domain\Support;

use App\Enums\AgeGroup;
use App\Models\AiRequest;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * KI-Aufteilung über OpenRouter (§11.6). Nur Titel, Notiz, Altersgruppe und Schrittzahl gehen raus –
 * keine Namen, keine Familien- oder Gesundheitsdaten. Anbieter mit Datenspeicherung sind ausgeschlossen.
 */
class OpenRouterClient
{
    private const SYSTEM = 'Du hilfst Kindern, Jugendlichen und Erwachsenen, eine Aufgabe in kleine Schritte zu zerlegen. '
        .'Antworte nur mit JSON: {"steps": ["…"]}. Jeder Schritt beginnt mit einem Verb, ist höchstens 8 Wörter lang, '
        .'konkret und sicher (keine Chemikalien, kein Strom, keine Messer bei Kindern). Keine Erklärungen oder weiteren JSON-Felder. '
        .'Sprache: Deutsch.';

    private const ADULT_SYSTEM = 'Du hilfst Erwachsenen, eine Aufgabe in kleine Schritte zu zerlegen. '
        .'Antworte nur mit JSON: {"steps": ["…"]}. Jeder Schritt beginnt mit einem Verb, ist höchstens 8 Wörter lang '
        .'und konkret umsetzbar. Übliche Werkzeuge sind erlaubt; weise bei erkennbaren Risiken auf Schutzmaßnahmen hin. '
        .'Gib keine Anleitung zu illegalen oder hochriskanten Tätigkeiten, die Fachkenntnis erfordern, etwa Arbeiten '
        .'an Netzspannung, Gas oder gefährlichen Chemikalien. Keine Erklärungen oder weiteren JSON-Felder. Sprache: Deutsch.';

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
        $responsePayload = null;
        $responseStatus = null;
        $finishReason = null;
        $error = null;
        $steps = null;
        $attempts = [];

        try {
            $systemPrompt = $user->ageGroup() === AgeGroup::Adult ? self::ADULT_SYSTEM : self::SYSTEM;
            foreach ([1600, 3000] as $maxTokens) {
                $response = Http::withToken(config('services.openrouter.key'))->timeout(20)
                    ->withHeaders(['X-Title' => 'Heldenhaus'])
                    ->post('https://openrouter.ai/api/v1/chat/completions', [
                        'model' => $model,
                        'messages' => [
                            ['role' => 'system', 'content' => $systemPrompt],
                            ['role' => 'user', 'content' => $prompt],
                        ],
                        'response_format' => ['type' => 'json_object'],
                        'max_tokens' => $maxTokens,
                        'temperature' => 0.3,
                        'provider' => ['data_collection' => 'deny'],
                        'usage' => ['include' => true],
                    ]);

                $responseStatus = $response->status();
                $finishReason = $response->json('choices.0.finish_reason');
                $attempts[] = [
                    'max_tokens' => $maxTokens,
                    'status' => $responseStatus,
                    'finish_reason' => $finishReason,
                    'body' => mb_substr($response->body(), 0, 10000),
                ];
                $responseUsage = (array) $response->json('usage');
                $usage['prompt_tokens'] += (int) ($responseUsage['prompt_tokens'] ?? 0);
                $usage['completion_tokens'] += (int) ($responseUsage['completion_tokens'] ?? 0);
                $cost += (float) ($responseUsage['cost'] ?? 0);

                if (! $response->successful()) {
                    $error = 'OpenRouter antwortete mit HTTP '.$responseStatus.'.';
                    break;
                }

                $content = $response->json('choices.0.message.content');
                $steps = is_string($content) ? self::parse($content, $maxSteps) : null;
                if ($finishReason !== 'length') {
                    if ($steps === null) {
                        $error = 'Die OpenRouter-Antwort enthielt keine gültige Schritteliste.';
                    }
                    break;
                }
            }

            if ($steps === null && $finishReason === 'length') {
                $error = 'Die OpenRouter-Antwort wurde auch beim Wiederholungsversuch wegen des Token-Limits abgeschnitten.';
            }
            $responsePayload = json_encode(['attempts' => $attempts], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
            $responsePayload = $attempts
                ? json_encode(['attempts' => $attempts], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null;
        }

        $request = AiRequest::create([
            'family_id' => $user->family_id,
            'user_id' => $user->id,
            'purpose' => 'steps',
            'model' => $model,
            'prompt_tokens' => (int) $usage['prompt_tokens'],
            'completion_tokens' => (int) $usage['completion_tokens'],
            'cost_usd' => $cost,
            'ok' => $steps !== null,
            'response_payload' => $responsePayload,
            'response_status' => $responseStatus,
            'finish_reason' => $finishReason,
            'error_message' => $error,
        ]);

        if ($error !== null) {
            Log::warning('OpenRouter-Schrittvorschlag fehlgeschlagen', [
                'ai_request_id' => $request->id,
                'model' => $model,
                'status' => $responseStatus,
                'finish_reason' => $finishReason,
                'error' => mb_substr($error, 0, 500),
            ]);
        }

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
