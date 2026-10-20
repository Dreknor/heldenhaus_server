<?php

namespace App\Domain\Support;

use App\Domain\Gameplay\GameException;
use App\Domain\Integrations\HomeAssistant\HomeAssistantWebhooks;
use App\Enums\AgeGroup;
use App\Enums\TaskType;
use App\Models\LowEnergyDay;
use App\Models\RecoveryQuest;
use App\Models\SupportSetting;
use App\Models\TaskAssignment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Fokus & Alltagshilfen (Konzept §11): Einstellungen je Person, Energiespar-Tag,
 * Kern-Dailies und Erholungs-Quests für pausierte Serien. Neutral benannt, keine Diagnose.
 */
class SupportService
{
    /** Felder, die nur Eltern für Kinder festlegen (Teens und Erwachsene stellen sie selbst ein – außer dem Joker). */
    public const PARENT_FIELDS = ['gentle_mode', 'low_energy_per_week', 'ai_steps'];

    public static function settings(User $user): SupportSetting
    {
        return $user->relationLoaded('supportSetting') && $user->supportSetting
            ? $user->supportSetting
            : ($user->supportSetting()->first() ?? new SupportSetting(['user_id' => $user->id]));
    }

    /** Sanfter Modus: eigene Einstellung, sonst Standard der Familie. */
    public static function gentle(User $user): bool
    {
        return self::settings($user)->gentle_mode ?? (bool) $user->family?->setting('gentle_mode_default', false);
    }

    public static function present(User $user): array
    {
        $s = self::settings($user);

        return [
            'gentle_mode' => self::gentle($user),
            'gentle_mode_own' => $s->gentle_mode,
            'focus_mode_default' => $s->focus_mode_default,
            'feedback_level' => $s->feedback_level,
            'reduce_motion' => $s->reduce_motion,
            'sound' => $s->sound,
            'haptics' => $s->haptics,
            'timer_style' => $s->timer_style,
            'max_steps' => $s->max_steps,
            'low_energy_per_week' => $s->low_energy_per_week,
            'low_energy_left' => max(0, $s->low_energy_per_week - self::lowEnergyThisWeek($user)),
            'low_energy_today' => self::isLowEnergy($user, $user->gameDate()),
            'ai_steps' => $s->ai_steps,
            'can_change' => self::editableBy($user, $user),
        ];
    }

    /** Welche Felder darf $actor bei $target ändern? */
    public static function editableBy(User $actor, User $target): array
    {
        $all = ['gentle_mode', 'focus_mode_default', 'feedback_level', 'reduce_motion', 'sound', 'haptics', 'timer_style', 'max_steps', 'low_energy_per_week', 'ai_steps'];

        if ($actor->isParent() && $actor->sameFamilyAs($target)) {
            return $all;
        }
        if (! $actor->is($target)) {
            return [];
        }

        // Kinder: Darstellung ja, Spielregeln setzen die Eltern. Teens: auch den sanften Modus selbst.
        return match ($target->ageGroup()) {
            AgeGroup::Child => array_values(array_diff($all, self::PARENT_FIELDS)),
            AgeGroup::Teen => array_values(array_diff($all, ['low_energy_per_week', 'ai_steps'])),
            AgeGroup::Adult => $all,
        };
    }

    public function update(User $actor, User $target, array $data): SupportSetting
    {
        $allowed = self::editableBy($actor, $target);
        $forbidden = array_diff(array_keys($data), $allowed);
        if ($forbidden !== []) {
            throw new GameException('Das stellen deine Eltern ein.');
        }

        $setting = SupportSetting::firstOrNew(['user_id' => $target->id]);
        $setting->fill($data);
        $setting->set_by = $actor->id;
        $setting->save();

        return $setting;
    }

    // ---------- Energiespar-Tag ----------

    public static function isLowEnergy(User $user, \DateTimeInterface $date): bool
    {
        return LowEnergyDay::where('user_id', $user->id)->whereDate('date', $date->format('Y-m-d'))->exists();
    }

    public static function lowEnergyThisWeek(User $user): int
    {
        $today = $user->gameDate();

        return LowEnergyDay::where('user_id', $user->id)
            ->whereDate('date', '>=', $today->startOfWeek()->toDateString())
            ->whereDate('date', '<=', $today->endOfWeek()->toDateString())
            ->count();
    }

    /** Energiespar-Tag für heute – selbst mit einem Tipp, für Kinder auch durch Eltern. Ohne Begründung. */
    public function startLowEnergy(User $actor, User $target): LowEnergyDay
    {
        if (! $actor->is($target) && ! ($actor->isParent() && $actor->sameFamilyAs($target))) {
            throw new GameException('Das geht nur für dich selbst.');
        }

        $today = $target->gameDate();
        if (self::isLowEnergy($target, $today)) {
            throw new GameException('Heute ist schon Energiespar-Tag.');
        }
        if (self::lowEnergyThisWeek($target) >= self::settings($target)->low_energy_per_week) {
            throw new GameException('Die Energiespar-Tage für diese Woche sind aufgebraucht. Für längere Pausen gibt es das Gasthaus.');
        }

        $day = LowEnergyDay::create(['user_id' => $target->id, 'date' => $today->toDateString(), 'set_by' => $actor->id]);
        app(HomeAssistantWebhooks::class)->send($target->family_id, 'low_energy_day', HomeAssistantWebhooks::member($target));

        return $day;
    }

    public function cancelLowEnergy(User $actor, User $target): void
    {
        if (! $actor->is($target) && ! ($actor->isParent() && $actor->sameFamilyAs($target))) {
            throw new GameException('Das geht nur für dich selbst.');
        }

        LowEnergyDay::where('user_id', $target->id)->whereDate('date', $target->gameDate()->toDateString())->delete();
    }

    /**
     * Kern-Dailies eines Tages: markierte, sonst die zwei leichtesten fälligen.
     *
     * @param  Collection<int, TaskAssignment>  $due  fällige Daily-Zuweisungen
     * @return Collection<int, int> Assignment-IDs
     */
    public static function coreIds(Collection $due): Collection
    {
        $marked = $due->filter(fn (TaskAssignment $a) => $a->task->is_core);
        if ($marked->isNotEmpty()) {
            return $marked->pluck('id');
        }

        return $due->sortBy([fn ($a, $b) => $a->task->difficulty <=> $b->task->difficulty, fn ($a, $b) => $a->id <=> $b->id])
            ->take(2)->pluck('id')->values();
    }

    /** Zählt eine Daily heute nicht (Energiespar-Tag, kein Kern)? Dann gibt es den „Trotzdem geschafft“-Bonus. */
    public static function isExtraOnLowEnergy(TaskAssignment $assignment): bool
    {
        $task = $assignment->task;
        $today = $assignment->user->gameDate();
        if ($task->type !== TaskType::Daily || ! self::isLowEnergy($assignment->user, $today)) {
            return false;
        }

        $due = TaskAssignment::with('task')->where('user_id', $assignment->user_id)
            ->whereHas('task', fn ($q) => $q->where('type', TaskType::Daily)->whereNull('archived_at'))
            ->get()->filter(fn (TaskAssignment $a) => $a->task->isDueFor($today, $a->user_id));

        return ! self::coreIds($due)->contains($assignment->id);
    }

    // ---------- Serien: pausieren und reparieren ----------

    /** Verpasste Daily mit laufender Serie: pausieren statt zurücksetzen und eine Erholungs-Quest starten. */
    public static function pauseStreak(TaskAssignment $a, CarbonImmutable $day): void
    {
        if ($a->streak <= 0) {
            return;
        }

        if ($a->streak_paused_at === null) {
            $a->streak_paused_at = now();
            RecoveryQuest::create([
                'user_id' => $a->user_id,
                'assignment_id' => $a->id,
                'needed' => (int) config('game.recovery.needed', 2),
                'streak_to_restore' => $a->streak,
                'expires_on' => $day->addDays((int) config('game.recovery.days', 4))->toDateString(),
            ]);
        }
    }

    /** Erledigte pausierte Daily: Fortschritt der Erholungs-Quest. */
    public static function recoveryProgress(TaskAssignment $a, int $step = 1): ?RecoveryQuest
    {
        if ($a->streak_paused_at === null) {
            return null;
        }

        $quest = RecoveryQuest::where('assignment_id', $a->id)->where('status', 'active')->latest('id')->first();
        if ($quest === null) {
            return null;
        }

        $quest->done = max(0, $quest->done + $step);
        if ($quest->done >= $quest->needed) {
            $quest->status = 'restored';
            $a->streak = $quest->streak_to_restore;
            $a->streak_paused_at = null;
        }
        $quest->save();

        return $quest;
    }

    /** Abgelaufene Erholungs-Quests: Serie beginnt neu, der Bestwert bleibt. */
    public static function expireRecoveries(User $user, CarbonImmutable $today): void
    {
        RecoveryQuest::with('assignment')->where('user_id', $user->id)->where('status', 'active')
            ->whereDate('expires_on', '<', $today->toDateString())->get()
            ->each(function (RecoveryQuest $q) {
                $q->update(['status' => 'failed']);
                $q->assignment?->forceFill(['streak' => 0, 'streak_paused_at' => null])->save();
            });
    }

    public static function presentRecoveries(User $user): array
    {
        return RecoveryQuest::with('assignment.task')->where('user_id', $user->id)->where('status', 'active')->get()
            ->map(fn (RecoveryQuest $q) => [
                'id' => $q->id,
                'task' => $q->assignment?->task?->title,
                'assignment_id' => $q->assignment_id,
                'needed' => $q->needed,
                'done' => $q->done,
                'streak' => $q->streak_to_restore,
                'expires_on' => $q->expires_on->toDateString(),
            ])->values()->all();
    }
}
