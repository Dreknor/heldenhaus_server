<?php

namespace App\Domain\Integrations\HomeAssistant;

use App\Enums\TaskType;
use App\Jobs\SendHomeAssistantWebhook;
use App\Models\HaWebhook;
use App\Models\TaskAssignment;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Ausgehende Webhooks an Home Assistant (Konzept §4.2/§4.5): HMAC-SHA256-signiert mit Zeitstempel,
 * zugestellt über die Queue mit Wiederholung bei Fehlern. In HA kommen die Ereignisse als
 * heldenhaus_<event> an (z. B. heldenhaus_reward_redeemed mit action_key „screen_time_30“).
 */
class HomeAssistantWebhooks
{
    public const EVENTS = [
        'task_completed' => 'Aufgabe erledigt',
        'approval_requested' => 'Freigabe wartet',
        'all_dailies_done' => 'Alle Dailies erledigt',
        'level_up' => 'Level-up',
        'reward_redeemed' => 'Belohnung eingelöst (mit Aktions-Schlüssel)',
        'boss_defeated' => 'Boss besiegt',
        'quest_completed' => 'Sammel-Quest geschafft',
        'step_completed' => 'Teilschritt erledigt',
        'focus_started' => 'Fokus-Zeit gestartet',
        'focus_ended' => 'Fokus-Zeit beendet',
        'low_energy_day' => 'Energiespar-Tag',
    ];

    /** Zeitfenster, in dem HA eine Signatur akzeptieren sollte (gegen Replay). */
    public const TOLERANCE_SECONDS = 300;

    public function send(?int $familyId, string $event, array $data = []): bool
    {
        if (! $familyId) {
            return false;
        }

        $webhook = HaWebhook::where('family_id', $familyId)->first();
        if ($webhook === null || ! ($event === 'ping' || $webhook->wants($event))) {
            return false;
        }

        SendHomeAssistantWebhook::dispatch($webhook->id, [
            'id' => (string) Str::uuid(),
            'event' => $event,
            'family_id' => $familyId,
            'occurred_at' => now()->toIso8601String(),
            'data' => $data,
        ])->afterCommit();

        return true;
    }

    /** Header für einen Body: Signatur über „<zeitstempel>.<body>“. */
    public static function headers(string $secret, string $body, string $event, string $deliveryId, ?int $timestamp = null): array
    {
        $timestamp ??= now()->getTimestamp();

        return [
            'X-Heldenhaus-Event' => $event,
            'X-Heldenhaus-Delivery' => $deliveryId,
            'X-Heldenhaus-Timestamp' => (string) $timestamp,
            'X-Heldenhaus-Signature' => 'sha256='.hash_hmac('sha256', "{$timestamp}.{$body}", $secret),
        ];
    }

    /** Gegenstück für Empfänger (und Tests): Signatur und Zeitfenster prüfen. */
    public static function verify(string $secret, string $body, string $timestamp, string $signature, ?int $now = null): bool
    {
        $now ??= now()->getTimestamp();
        if (! ctype_digit($timestamp) || abs($now - (int) $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }

        return hash_equals('sha256='.hash_hmac('sha256', "{$timestamp}.{$body}", $secret), $signature);
    }

    public static function member(User $user): array
    {
        return ['member_id' => $user->id, 'member_name' => $user->name];
    }

    /** Fällige Dailies einer Person heute: [fällig, erledigt]. */
    public static function dailies(User $user): array
    {
        $today = $user->gameDate();
        $dailies = TaskAssignment::with('task')->where('user_id', $user->id)
            ->whereHas('task', fn ($q) => $q->where('type', TaskType::Daily)->whereNull('archived_at'))
            ->get()
            ->filter(fn (TaskAssignment $a) => $a->task->isDueFor($today, $a->user_id));

        return [$dailies->count(), $dailies->where('completed', true)->count()];
    }
}
