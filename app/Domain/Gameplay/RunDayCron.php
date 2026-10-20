<?php

namespace App\Domain\Gameplay;

use App\Domain\Achievements\AchievementService;
use App\Domain\Classes\ModifierChain;
use App\Domain\Quests\QuestService;
use App\Domain\Support\SupportService;
use App\Enums\TaskType;
use App\Models\DayLog;
use App\Models\TaskAssignment;
use App\Models\User;
use App\Models\UserStats;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Tageswechsel einer Person: verpasste Dailies auswerten, Tagesstatus zurücksetzen.
 * Läuft „lazy“ beim ersten Aufruf des Tages und zusätzlich per Scheduler.
 */
class RunDayCron
{
    public function __construct(private readonly ScoreTask $scoreTask) {}

    public static function isPending(User $user, ?\DateTimeInterface $now = null): bool
    {
        $last = $user->stats?->last_cron_on;

        return $last !== null && CarbonImmutable::parse($last->format('Y-m-d'))->lt($user->gameDate($now));
    }

    /**
     * Dailies, die am letzten Spieltag fällig, aber nicht erledigt waren – für den Dialog „Gestern noch erledigt?“.
     *
     * @return Collection<int, TaskAssignment>
     */
    public static function openFromLastDay(User $user, ?\DateTimeInterface $now = null): Collection
    {
        if (! self::isPending($user, $now)) {
            return collect();
        }

        $evalDay = CarbonImmutable::parse($user->stats->last_cron_on->format('Y-m-d'));

        return self::dailyAssignments($user)
            ->filter(fn (TaskAssignment $a) => ! $a->completed && $a->task->isDueFor($evalDay, $a->user_id))
            ->values();
    }

    /**
     * @param  list<int>  $doneYesterday  Assignment-IDs, die im Dialog nachträglich abgehakt wurden
     */
    public function __invoke(User $user, ?\DateTimeInterface $now = null, array $doneYesterday = []): ?DayLog
    {
        $stats = $user->stats()->firstOrCreate([], ['hp' => config('game.max_hp')]);
        $today = $user->gameDate($now);

        if ($stats->last_cron_on === null) {
            $stats->update(['last_cron_on' => $today->toDateString()]);

            return null;
        }

        if (! self::isPending($user->setRelation('stats', $stats), $now)) {
            return null;
        }

        // Im Dialog nachgetragene Aufgaben zählen noch für den alten Tag.
        $open = self::openFromLastDay($user, $now)->keyBy('id');
        foreach ($doneYesterday as $assignmentId) {
            if ($open->has($assignmentId)) {
                ($this->scoreTask)($open->get($assignmentId), 'up', 'web');
            }
        }

        $log = DB::transaction(function () use ($user, $today) {
            $stats = UserStats::lockForUpdate()->findOrFail($user->id);

            // Doppelte Ausführung (Web + Scheduler gleichzeitig) verhindern
            if (! CarbonImmutable::parse($stats->last_cron_on->format('Y-m-d'))->lt($today)) {
                return null;
            }

            // Ausgewertet wird der zuletzt gespielte Tag; mehrere verpasste Tage zählen nur einmal (kindgerecht).
            $evalDay = CarbonImmutable::parse($stats->last_cron_on->format('Y-m-d'));
            $family = $user->family;
            $ageFactor = Formulas::ageDamageFactor($user->ageGroup(), $family);
            $con = Attributes::for($stats)['con'];
            // Schildwall (Krieger in der Familie) & Co. – als Faktor auf jeden einzelnen Schaden
            $damageFactor = ModifierChain::applyCronDamage($user, $stats, 1.0);

            // Fokus & Alltagshilfen (§11): sanfter Modus ohne HP-Schaden, am Energiespar-Tag zählen nur Kern-Dailies
            $gentle = SupportService::gentle($user);
            $lowEnergy = SupportService::isLowEnergy($user, $evalDay);
            SupportService::expireRecoveries($user, $today);

            $assignments = self::dailyAssignments($user);
            $dueToday = $assignments->filter(fn (TaskAssignment $a) => $a->task->isDueFor($evalDay, $a->user_id));
            $counted = $lowEnergy ? SupportService::coreIds($dueToday) : $dueToday->pluck('id');

            $due = 0;
            $done = 0;
            $damage = 0.0;
            $missed = [];

            foreach ($assignments as $a) {
                if ($counted->contains($a->id)) {
                    $due++;

                    if ($a->completed) {
                        $done++;
                    } else {
                        $delta = Formulas::delta($a->value);
                        $valueBefore = $a->value;
                        $a->value = Formulas::clampValue($a->value - $delta);

                        if (! $stats->sleeping) {
                            $hit = $gentle ? 0.0 : round(Formulas::damage($delta, $a->task->difficulty, $con, $ageFactor) * $damageFactor, 2);
                            $damage += $hit;
                            // Für „Zweite Chance“ (Heiler) merken
                            $missed[] = ['assignment_id' => $a->id, 'damage' => $hit, 'streak' => $a->streak, 'value' => $valueBefore];

                            // Serie: erst Streak-Schutz, sonst pausieren statt auf 0 (Erholungs-Quest repariert sie)
                            if ($stats->streak_shields > 0 && $a->streak > 0 && $a->streak_paused_at === null) {
                                $stats->streak_shields--;
                            } else {
                                SupportService::pauseStreak($a, $evalDay);
                            }
                        }
                    }
                }

                $a->completed = false;
                $a->completed_at = null;
                $a->checklist_done = null;
                $a->save();
            }

            // Zähler der Gewohnheiten gelten pro Tag
            TaskAssignment::where('user_id', $user->id)
                ->whereHas('task', fn ($q) => $q->where('type', TaskType::Habit))
                ->update(['counter_up' => 0, 'counter_down' => 0]);

            $knockedOut = $damage > 0 && Progression::takeDamage($stats, $damage, $family);

            // Verpasste Dailies heilen den Familien-Boss. Im sanften Modus wirken Schutz-Fähigkeiten (Schildwall) auf die Heilung.
            app(QuestService::class)->healOnMissed($user, $gentle ? (int) round(count($missed) * $damageFactor) : count($missed));

            $ratio = $due > 0 ? $done / $due : 1;
            $stats->mp = min(Formulas::maxMp($stats), $stats->mp + Formulas::maxMp($stats) * config('game.cron_mp_regen') * $ratio);
            $stats->last_cron_on = $today->toDateString();
            $stats->save();

            return DayLog::updateOrCreate(
                ['user_id' => $user->id, 'date' => $evalDay->toDateString()],
                [
                    'dailies_due' => $due,
                    'dailies_done' => $done,
                    'damage' => round($damage, 2),
                    'knocked_out' => $knockedOut,
                    'sleeping' => $stats->sleeping,
                    'missed' => $missed,
                    'low_energy' => $lowEnergy,
                    'gentle' => $gentle,
                ],
            );
        });

        if ($log !== null) {
            app(AchievementService::class)->evaluate($user, ['perfect_days']);
        }

        return $log;
    }

    /** @return Collection<int, TaskAssignment> */
    private static function dailyAssignments(User $user): Collection
    {
        return TaskAssignment::with('task')
            ->where('user_id', $user->id)
            ->whereHas('task', fn ($q) => $q->where('type', TaskType::Daily)->whereNull('archived_at'))
            ->get();
    }
}
