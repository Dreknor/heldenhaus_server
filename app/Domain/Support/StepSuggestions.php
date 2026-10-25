<?php

namespace App\Domain\Support;

use App\Domain\Gameplay\GameException;
use App\Enums\AgeGroup;
use App\Models\AiRequest;
use App\Models\StepTemplate;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Schritt-Vorschläge (§11.2) in dieser Reihenfolge: Vorlagen (ohne Internet), gespeicherte Schritte
 * der Familie, KI über OpenRouter – nur wenn die Eltern sie freigeschaltet haben.
 */
class StepSuggestions
{
    public function __construct(private readonly OpenRouterClient $ai) {}

    public static function key(string $title): string
    {
        return Str::of($title)->lower()->replaceMatches('/[^\pL\pN ]+/u', ' ')->squish()->limit(120, '')->toString();
    }

    public static function templates(): array
    {
        static $templates;

        return $templates ??= json_decode(file_get_contents(database_path('content/chunk_templates.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array{source: string, steps: list<string>}|null */
    public function suggest(User $user, Task $task, bool $allowAi = true, bool $ignoreRateLimit = false): ?array
    {
        $max = SupportService::settings($user)->max_steps;
        $max = $user->ageGroup() === AgeGroup::Child ? min($max, 5) : $max;
        $key = self::key($task->title);

        $saved = StepTemplate::where('family_id', $user->family_id)->where('title_key', $key)->first();
        if ($saved) {
            return ['source' => 'family', 'steps' => array_slice($saved->steps, 0, $max)];
        }

        foreach (self::templates() as $template) {
            foreach ($template['match'] as $word) {
                if (str_contains($key, $word)) {
                    return ['source' => 'template', 'steps' => array_slice($template['steps'], 0, $max)];
                }
            }
        }

        if (! $allowAi || ! $this->aiAllowed($user)) {
            return null;
        }

        $steps = $this->requestAiSteps($user, $task, $max, $ignoreRateLimit);

        return $steps ? ['source' => 'ai', 'steps' => $steps] : null;
    }

    /** KI-Schritte bei aktivierter persönlicher Freigabe direkt an neu erstellte Aufgaben übernehmen. */
    public function suggestOnCreate(User $user, Task $task): ?array
    {
        if (! SupportService::settings($user)->ai_steps || ! $this->aiAllowed($user)) {
            return null;
        }

        $max = SupportService::settings($user)->max_steps;
        $max = $user->ageGroup() === AgeGroup::Child ? min($max, 5) : $max;
        $steps = $this->requestAiSteps($user, $task, $max, ignoreRateLimit: true);

        if ($steps === null) {
            return null;
        }

        $this->save($user, $task, $steps, 'ai');

        return ['source' => 'ai', 'steps' => $steps];
    }

    /** @return list<string>|null */
    private function requestAiSteps(User $user, Task $task, int $max, bool $ignoreRateLimit): ?array
    {
        $limiter = 'ai-steps:'.$user->id;
        if (RateLimiter::tooManyAttempts($limiter, (int) config('game.ai.requests_per_hour'))) {
            if ($ignoreRateLimit) {
                return null;
            }

            throw new GameException('Gerade zu viele Vorschläge – probier es später noch einmal.');
        }
        RateLimiter::hit($limiter, 3600);

        return $this->ai->steps($user, $task->title, $task->notes, $max);
    }

    /** Übernommene Schritte an der Aufgabe speichern und als Familienvorlage merken. */
    public function save(User $user, Task $task, array $steps, string $source = 'family'): Task
    {
        $steps = array_values(array_filter(array_map(fn ($s) => mb_substr(trim((string) $s), 0, 120), $steps)));
        $task->update(['checklist' => $steps ?: null]);

        if ($steps && $user->family_id) {
            StepTemplate::updateOrCreate(
                ['family_id' => $user->family_id, 'title_key' => self::key($task->title)],
                ['steps' => $steps, 'source' => $source === 'ai' ? 'ai' : 'family'],
            );
        }

        return $task;
    }

    public function aiAllowed(User $user): bool
    {
        $family = $user->family;
        if (! OpenRouterClient::configured() || ! $family?->setting('ai_enabled', false) || ! SupportService::settings($user)->ai_steps) {
            return false;
        }

        $spent = AiRequest::where('family_id', $family->id)->where('created_at', '>=', now()->startOfMonth())->sum('cost_usd');

        return $spent < (float) $family->setting('ai_budget_usd', config('game.ai.monthly_budget_usd'));
    }
}
