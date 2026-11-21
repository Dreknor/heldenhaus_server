<?php

namespace App\Domain\Goals;

use App\Domain\Gameplay\GameException;
use App\Domain\Integrations\HomeAssistant\HomeAssistantWebhooks;
use App\Domain\Notifications\PushNotifier;
use App\Enums\TaskType;
use App\Models\FocusSession;
use App\Models\NotificationSetting;
use App\Models\ReminderLog;
use App\Models\TaskAssignment;
use App\Models\TaskCompletion;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Erinnerungen und Wenn-dann-Pläne (§14.2). Die App plant Erinnerungen lokal aus den Abgleich-Daten (Inhalte bleiben auf dem Gerät);
 * der Server schickt zusätzlich eine inhaltsleere Push-Nachricht („Eine Erinnerung wartet“) für Web/PWA und – nur wenn gewünscht
 * und nie bei privaten Aufgaben – das HA-Ereignis heldenhaus_reminder für Signale am Ort (z. B. Badlicht).
 *
 * Grenzen: eine Erinnerung je Aufgabe und Tag („Später“ verschiebt sie), höchstens 5 je Person und Tag, Ruhezeiten und
 * laufende Fokus-Zeiten halten Erinnerungen zurück. Nach drei ignorierten Erinnerungen in Folge fragt die App einmal nach.
 */
class ReminderService
{
    public const MAX_PER_DAY = 5;

    public const IGNORED_BEFORE_QUESTION = 3;

    public const SNOOZE_MINUTES = [15, 60];

    public function __construct(
        private readonly PushNotifier $push,
        private readonly HomeAssistantWebhooks $webhooks,
    ) {}

    /** Fällige Erinnerungen senden (Scheduler, jede Minute). */
    public function dispatchDue(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $sent = 0;

        $assignments = TaskAssignment::with(['task', 'user.notificationSetting'])
            ->whereHas('task', fn ($q) => $q->whereNotNull('remind_at')->whereNull('archived_at')->whereNull('mastered_at'))
            ->get();

        foreach ($assignments as $a) {
            if ($this->shouldSend($a, $now)) {
                $this->send($a, $now);
                $sent++;
            }
        }

        return $sent;
    }

    public function shouldSend(TaskAssignment $a, CarbonImmutable $now): bool
    {
        $task = $a->task;
        $user = $a->user;
        $today = $user->gameDate($now);
        $local = $now->setTimezone($user->timezone ?: 'Europe/Berlin');

        if ($task->paused_until !== null && $today->toDateString() <= $task->paused_until->toDateString()) {
            return false;
        }

        // Schon erledigt oder heute nicht dran
        $open = match ($task->type) {
            TaskType::Daily => ! $a->completed && $task->isDueFor($today, $a->user_id),
            TaskType::Todo => ! $a->completed,
            TaskType::Habit => $a->counter_up === 0,
            default => false,
        };
        if (! $open) {
            return false;
        }

        $snoozed = $a->reminder_snoozed_until !== null && $a->reminder_snoozed_until->isFuture();
        if ($snoozed || $local->format('H:i:s') < CarbonImmutable::parse($task->remind_at)->format('H:i:s')) {
            return false;
        }

        // Eine Erinnerung je Aufgabe und Tag; „Später“ erlaubt genau eine Wiederholung nach der Pause
        $log = ReminderLog::where('assignment_id', $a->id)->whereDate('date', $today->toDateString())->first();
        $resend = $log !== null && $a->reminder_snoozed_until !== null && $log->sent_at->lt($a->reminder_snoozed_until);
        if ($log !== null && ! $resend) {
            return false;
        }

        if ($log === null && ReminderLog::where('user_id', $user->id)->whereDate('date', $today->toDateString())->count() >= self::MAX_PER_DAY) {
            return false;
        }

        // Ruhezeiten (§1) und Fokus-Zeit (§11.4) halten zurück – die Erinnerung kommt danach, wenn noch Zeit ist
        $setting = $user->notificationSetting ?? new NotificationSetting;
        if ($setting->isQuiet($local)) {
            return false;
        }

        return ! FocusSession::where('user_id', $user->id)->whereNull('ended_at')->where('ends_at', '>', $now)->exists();
    }

    private function send(TaskAssignment $a, CarbonImmutable $now): void
    {
        $task = $a->task;
        $user = $a->user;
        $today = $user->gameDate($now)->toDateString();

        $log = ReminderLog::where('assignment_id', $a->id)->whereDate('date', $today)->first();
        $log === null
            ? ReminderLog::create(['assignment_id' => $a->id, 'user_id' => $user->id, 'date' => $today, 'sent_at' => $now])
            : $log->update(['sent_at' => $now]);

        $a->forceFill(['reminder_snoozed_until' => null])->save();

        // Inhaltsleer: keine Titel über Google (§7). Die App zeigt die Aufgabe beim Öffnen.
        $this->push->notify($user, 'reminder', ['assignment_id' => (string) $a->id]);

        if ($task->remind_ha && ! $task->is_private && $user->family_id) {
            $this->webhooks->send($user->family_id, 'reminder', [
                'member' => $user->name,
                'user_id' => $user->id,
                'task_id' => $task->id,
                'task_key' => $task->ha_event_key ?: "task_{$task->id}",
                'title' => $task->title,
                'anchor' => $task->anchor,
            ]);
        }
    }

    /** Erledigt am Tag einer Erinnerung: für „Lernen statt nerven“ merken. */
    public function onCompletion(TaskCompletion $completion): void
    {
        if ($completion->direction !== 'up' || ! $completion->assignment_id) {
            return;
        }

        $user = $completion->user;
        ReminderLog::where('assignment_id', $completion->assignment_id)
            ->whereDate('date', $user->gameDate($completion->created_at)->toDateString())
            ->update(['completed' => true]);
    }

    /**
     * Vergangene Tage auswerten: ignorierte Erinnerungen zählen, erledigte setzen den Zähler zurück.
     * Nach drei ignorierten in Folge stellt die App einmal die Frage „Passt die Zeit noch?“.
     */
    public function evaluatePast(User $user, ?CarbonImmutable $now = null): int
    {
        $today = $user->gameDate($now)->toDateString();
        $logs = ReminderLog::with('assignment')->where('user_id', $user->id)->where('evaluated', false)
            ->whereDate('date', '<', $today)->orderBy('date')->get();

        foreach ($logs as $log) {
            $a = $log->assignment;
            if ($a !== null) {
                $a->ignored_reminders = $log->completed ? 0 : min(255, $a->ignored_reminders + 1);
                if ($a->ignored_reminders >= self::IGNORED_BEFORE_QUESTION) {
                    $a->reminder_question = true;
                }
                $a->save();
            }
            $log->update(['evaluated' => true]);
        }

        return $logs->count();
    }

    /** „Später“: 15 oder 60 Minuten. */
    public function snooze(User $user, TaskAssignment $a, int $minutes): CarbonImmutable
    {
        $this->authorize($user, $a);
        if (! in_array($minutes, self::SNOOZE_MINUTES, true)) {
            throw new GameException('Später geht um 15 oder 60 Minuten.');
        }

        $until = CarbonImmutable::now()->addMinutes($minutes);
        $a->forceFill(['reminder_snoozed_until' => $until])->save();

        return $until;
    }

    /**
     * Antwort auf „Passt die Zeit noch?“: andere Zeit, anderer Anker, Erinnerung aus oder Aufgabe pausieren (2 Wochen).
     * Bei Familienaufgaben ändert ein Kind nur die eigene Frage, Zeit und Anker bleiben Sache der Eltern.
     */
    public function feedback(User $user, TaskAssignment $a, string $action, ?string $value = null): void
    {
        $this->authorize($user, $a);
        $task = $a->task;
        $canEditTask = $task->scope !== 'family' || $user->isParent();

        match ($action) {
            'keep' => null,
            'other_time' => $canEditTask && $value !== null && preg_match('/^\d{2}:\d{2}$/', $value)
                ? $task->update(['remind_at' => $value])
                : throw new GameException('Welche Uhrzeit passt besser?'),
            'other_anchor' => $canEditTask && trim((string) $value) !== ''
                ? $task->update(['anchor' => mb_substr(trim($value), 0, 80)])
                : throw new GameException('Wann und wo machst du das?'),
            'off' => $canEditTask ? $task->update(['remind_at' => null, 'remind_ha' => false]) : throw new GameException('Das stellen deine Eltern ein.'),
            'pause' => $canEditTask
                ? $task->update(['paused_until' => $user->gameDate()->addDays(14)->toDateString()])
                : throw new GameException('Das stellen deine Eltern ein.'),
            default => throw new GameException('Diese Antwort kenne ich nicht.'),
        };

        $a->forceFill(['ignored_reminders' => 0, 'reminder_question' => false])->save();
    }

    /** Für App und Board: Anker, Zeit und offene Frage je Aufgabe. */
    public static function present(TaskAssignment $a): array
    {
        $task = $a->task;

        return [
            'anchor' => $task->anchor,
            'remind_at' => $task->remind_at ? substr((string) $task->remind_at, 0, 5) : null,
            'remind_ha' => (bool) $task->remind_ha,
            'paused_until' => $task->paused_until?->toDateString(),
            'reminder_snoozed_until' => $a->reminder_snoozed_until?->toIso8601String(),
            'reminder_question' => (bool) $a->reminder_question,
        ];
    }

    private function authorize(User $user, TaskAssignment $a): void
    {
        if ($a->user_id !== $user->id && ! ($user->isParent() && $user->sameFamilyAs($a->user))) {
            throw new GameException('Das ist nicht deine Aufgabe.');
        }
    }
}
