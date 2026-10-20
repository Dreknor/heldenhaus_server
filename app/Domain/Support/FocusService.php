<?php

namespace App\Domain\Support;

use App\Domain\Gameplay\GameException;
use App\Domain\Gameplay\Progression;
use App\Domain\Integrations\HomeAssistant\HomeAssistantWebhooks;
use App\Models\FocusSession;
use App\Models\Task;
use App\Models\User;
use App\Models\UserStats;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Fokus-Zeiten (§11.4): visueller Timer zu einer Aufgabe, gemeinsame Fokus-Zeit („Körper-Doubling“).
 * Abbrechen hat keine Folgen. Wer eine gemeinsame Fokus-Zeit zusammen schafft, bekommt beide einen kleinen Bonus.
 */
class FocusService
{
    public function __construct(private readonly HomeAssistantWebhooks $webhooks) {}

    public static function running(User $user): ?FocusSession
    {
        return FocusSession::with('task:id,title,is_private')->where('user_id', $user->id)->whereNull('ended_at')
            ->where('ends_at', '>', now()->subHours(2))->latest('id')->first();
    }

    public function start(User $user, int $minutes, ?Task $task = null): FocusSession
    {
        if ($task && ! $task->assignments()->where('user_id', $user->id)->exists()) {
            throw new GameException('Das ist nicht deine Aufgabe.');
        }

        // Eine laufende Fokus-Zeit endet stillschweigend, wenn eine neue beginnt
        FocusSession::where('user_id', $user->id)->whereNull('ended_at')->update(['ended_at' => now(), 'result' => 'aborted']);

        $session = FocusSession::create([
            'user_id' => $user->id,
            'task_id' => $task?->id,
            'planned_minutes' => $minutes,
            'started_at' => now(),
            'ends_at' => now()->addMinutes($minutes),
        ]);

        $this->event($session, 'focus_started');

        return $session;
    }

    /** Ein Familienmitglied zur gemeinsamen Fokus-Zeit einladen: gleicher Timer für beide. */
    public function invite(User $user, FocusSession $session, User $member): FocusSession
    {
        $this->ensureOwn($user, $session);
        if (! $user->sameFamilyAs($member) || $member->is($user)) {
            throw new GameException('Einladen geht nur innerhalb der Familie.');
        }

        $session->group_uuid ??= (string) Str::uuid();
        $session->save();

        FocusSession::where('user_id', $member->id)->whereNull('ended_at')->update(['ended_at' => now(), 'result' => 'aborted']);

        $partner = FocusSession::create([
            'user_id' => $member->id,
            'planned_minutes' => $session->planned_minutes,
            'started_at' => now(),
            'ends_at' => $session->ends_at,
            'group_uuid' => $session->group_uuid,
            'invited_by' => $user->id,
        ]);
        $this->event($partner, 'focus_started');

        return $partner;
    }

    public function extend(User $user, FocusSession $session, int $minutes = 5): FocusSession
    {
        $this->ensureOwn($user, $session);
        $this->ensureRunning($session);

        $base = $session->ends_at->isPast() ? now() : $session->ends_at;
        $session->update(['ends_at' => $base->copy()->addMinutes($minutes), 'planned_minutes' => $session->planned_minutes + $minutes]);

        return $session;
    }

    public function finish(User $user, FocusSession $session, string $result): FocusSession
    {
        $this->ensureOwn($user, $session);
        $this->ensureRunning($session);

        return DB::transaction(function () use ($session, $result) {
            $session->update(['ended_at' => now(), 'result' => $result]);

            // Gemeinsam geschafft: Bonus, sobald die zweite Person auch „geschafft“ meldet
            if ($result === 'done' && $session->group_uuid) {
                $group = FocusSession::where('group_uuid', $session->group_uuid)->get();
                if ($group->count() > 1 && $group->every(fn (FocusSession $s) => $s->result === 'done') && $group->sum('bonus_gold') == 0) {
                    $bonus = (float) config('game.focus.shared_bonus_gold');
                    foreach ($group as $s) {
                        $stats = UserStats::lockForUpdate()->find($s->user_id);
                        Progression::changeGold($stats, $bonus);
                        $stats->save();
                        $s->update(['bonus_gold' => $bonus]);
                    }
                }
            }

            $this->event($session->refresh(), 'focus_ended');

            return $session;
        });
    }

    public static function present(?FocusSession $s): ?array
    {
        if ($s === null) {
            return null;
        }

        return [
            'id' => $s->id,
            'task_id' => $s->task_id,
            'task_title' => $s->task?->is_private ? null : $s->task?->title,
            'planned_minutes' => $s->planned_minutes,
            'started_at' => $s->started_at->toIso8601String(),
            'ends_at' => $s->ends_at->toIso8601String(),
            'remaining_seconds' => max(0, (int) now()->diffInSeconds($s->ends_at, false)),
            'ended_at' => $s->ended_at?->toIso8601String(),
            'result' => $s->result,
            'shared' => $s->group_uuid !== null,
            'partners' => $s->group_uuid
                ? FocusSession::with('user:id,name')->where('group_uuid', $s->group_uuid)->where('id', '!=', $s->id)->get()->pluck('user.name')->values()
                : [],
            'bonus_gold' => $s->bonus_gold,
        ];
    }

    private function event(FocusSession $s, string $event): void
    {
        $user = $s->user ?? User::find($s->user_id);
        $this->webhooks->send($user->family_id, $event, HomeAssistantWebhooks::member($user) + [
            'session_id' => $s->id,
            'planned_minutes' => $s->planned_minutes,
            'ends_at' => $s->ends_at->toIso8601String(),
            'remaining_minutes' => max(0, (int) ceil(now()->diffInSeconds($s->ends_at, false) / 60)),
            'result' => $s->result,
            'shared' => $s->group_uuid !== null,
        ]);
    }

    private function ensureOwn(User $user, FocusSession $session): void
    {
        if ($session->user_id !== $user->id) {
            throw new GameException('Das ist nicht deine Fokus-Zeit.');
        }
    }

    private function ensureRunning(FocusSession $session): void
    {
        if (! $session->isRunning()) {
            throw new GameException('Diese Fokus-Zeit ist schon vorbei.');
        }
    }
}
