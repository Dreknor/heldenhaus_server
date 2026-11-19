<?php

namespace App\Domain\Insights;

use App\Domain\Gameplay\GameException;
use App\Enums\FamilyRole;
use App\Models\Survey;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Erfolgsmessung (Konzept §14.10):
 * - Ausgangslage (nur Eltern, 3 Fragen 1–5): beim Start, nach 8 Wochen, danach alle 3 Monate.
 * - Stimmungs-Check (jedes Mitglied, 5 Smileys + optional Gründe aus einer Auswahl): alle 4 Wochen,
 *   zum ersten Mal 4 Wochen nach dem Beitritt. Kinder geben nur Smileys und Auswahlantworten (§7).
 * Überspringen ist erlaubt (answers = null) und zählt wie eine Antwort für die Fälligkeit.
 */
class SurveyService
{
    public const BASELINE = [
        'self_started' => ['text' => 'Wie oft werden Aufgaben ohne Erinnerung erledigt?', 'low' => 'nie', 'high' => 'fast immer', 'better' => 'up'],
        'conflicts' => ['text' => 'Wie oft gibt es Streit um Aufgaben?', 'low' => 'fast nie', 'high' => 'sehr oft', 'better' => 'down'],
        'independence' => ['text' => 'Wie selbständig sind die Kinder im Alltag?', 'low' => 'gar nicht', 'high' => 'sehr', 'better' => 'up'],
    ];

    public const MOOD_REASONS = [
        'too_many' => 'Zu viele Aufgaben',
        'boring' => 'Zu langweilig',
        'too_hard' => 'Zu schwer',
        'few_rewards' => 'Zu wenig Belohnung',
        'other' => 'Etwas anderes',
    ];

    public const MOOD_SMILEYS = [1 => '😞', 2 => '🙁', 3 => '😐', 4 => '🙂', 5 => '😄'];

    public const FIRST_FOLLOW_UP_DAYS = 56;   // nach 8 Wochen

    public const FOLLOW_UP_DAYS = 91;         // danach alle 3 Monate

    public const MOOD_DAYS = 28;              // alle 4 Wochen

    /** Fällige Umfrage für die Karte im Heldenpanel (Ausgangslage hat Vorrang). */
    public function due(User $user, ?CarbonImmutable $today = null): ?array
    {
        if ($user->family_id === null || $user->family_role === FamilyRole::Guest) {
            return null;
        }
        $today ??= $user->gameDate();

        if ($user->isParent() && $this->baselineDue($user, $today)) {
            $round = Survey::where('user_id', $user->id)->where('kind', 'baseline')->count();

            return [
                'kind' => 'baseline',
                'title' => $round === 0 ? 'Drei kurze Fragen zum Start' : 'Wie läuft es inzwischen?',
                'intro' => $round === 0
                    ? 'Damit ihr später seht, was sich verändert hat. Dauert eine halbe Minute.'
                    : 'Dieselben drei Fragen wie beim Start – so seht ihr den Vergleich.',
                'questions' => collect(self::BASELINE)->map(fn ($q, $key) => ['key' => $key, ...$q])->values()->all(),
            ];
        }

        if ($this->moodDue($user, $today)) {
            return [
                'kind' => 'mood',
                'title' => 'Macht Heldenhaus dir noch Spaß?',
                'intro' => 'Tipp einfach auf ein Gesicht.',
                'smileys' => collect(self::MOOD_SMILEYS)->map(fn ($s, $v) => ['value' => $v, 'icon' => $s])->values()->all(),
                'reasons' => collect(self::MOOD_REASONS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values()->all(),
            ];
        }

        return null;
    }

    public function baselineDue(User $user, CarbonImmutable $today): bool
    {
        $rows = Survey::where('user_id', $user->id)->where('kind', 'baseline')->orderBy('date')->get(['date']);
        if ($rows->isEmpty()) {
            return true;
        }
        $interval = $rows->count() === 1 ? self::FIRST_FOLLOW_UP_DAYS : self::FOLLOW_UP_DAYS;

        return CarbonImmutable::parse($rows->last()->date->toDateString(), $today->getTimezone())->addDays($interval)->lte($today);
    }

    public function moodDue(User $user, CarbonImmutable $today): bool
    {
        $last = Survey::where('user_id', $user->id)->where('kind', 'mood')->latest('date')->value('date');
        $since = $last ? CarbonImmutable::parse($last->toDateString(), $today->getTimezone()) : $user->gameDate($user->created_at ?? now());

        return $since->addDays(self::MOOD_DAYS)->lte($today);
    }

    /** Antwort speichern; null = überspringen. */
    public function submit(User $user, string $kind, ?array $answers): Survey
    {
        $today = $user->gameDate();
        $due = match ($kind) {
            'baseline' => $user->isParent() && $this->baselineDue($user, $today),
            'mood' => $user->family_role !== FamilyRole::Guest && $this->moodDue($user, $today),
            default => false,
        };
        if (! $due) {
            throw new GameException('Diese Umfrage ist gerade nicht dran.');
        }

        return Survey::create([
            'user_id' => $user->id,
            'family_id' => $user->family_id,
            'kind' => $kind,
            'date' => $today->toDateString(),
            'answers' => $answers === null ? null : self::clean($kind, $answers),
        ]);
    }

    /** Nur bekannte Schlüssel und Werte übernehmen. */
    public static function clean(string $kind, array $answers): array
    {
        if ($kind === 'baseline') {
            $out = [];
            foreach (array_keys(self::BASELINE) as $key) {
                $value = (int) ($answers[$key] ?? 0);
                if ($value < 1 || $value > 5) {
                    throw new GameException('Bitte beantworte alle drei Fragen.');
                }
                $out[$key] = $value;
            }

            return $out;
        }

        $mood = (int) ($answers['mood'] ?? 0);
        if ($mood < 1 || $mood > 5) {
            throw new GameException('Bitte wähle ein Gesicht.');
        }

        return [
            'mood' => $mood,
            'reasons' => array_values(array_intersect(array_keys(self::MOOD_REASONS), (array) ($answers['reasons'] ?? []))),
        ];
    }
}
