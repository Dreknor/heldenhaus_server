<?php

namespace App\Domain\Goals;

use App\Domain\Badges\BadgeService;
use App\Domain\Gameplay\GameException;
use App\Domain\Social\FeedService;
use App\Enums\CompletionStatus;
use App\Enums\TaskType;
use App\Models\DayLog;
use App\Models\LowEnergyDay;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskCompletion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Gewohnheit meistern (§14.3): Eine Daily oder positive Gewohnheit, die sitzt, darf die tägliche Liste verlassen.
 * Kriterium: ≥ 60 Tage aktiv, in den letzten 60 Tagen ≥ 80 % erledigt (Energiespar- und Gasthaus-Tage zählen nicht),
 * höchste Farbstufe (value ≥ 10); Gewohnheiten zusätzlich ≥ 5 positive Tage je voller Woche.
 * Das Angebot ist eine Karte ohne Pflicht: Meistern, Seltener (wird Wochenziel 5 von 7) oder Weitermachen (frühestens in 30 Tagen wieder).
 */
class MasteryService
{
    public const WINDOW_DAYS = 60;

    public const MIN_RATE = 0.8;

    public const MIN_VALUE = 10.0;

    public const HABIT_DAYS_PER_WEEK = 5;

    public const RECHECK_DAYS = 30;

    public const CHECK_WEEKS = 4;

    public function __construct(
        private readonly BadgeService $badges,
        private readonly FeedService $feed,
        private readonly GoalService $goals,
    ) {}

    /** Kommt die Aufgabe für diese Person zum Meistern in Frage? Familienaufgaben nie (sie bleiben nötig). */
    public function eligible(TaskAssignment $a, ?CarbonImmutable $now = null): bool
    {
        $task = $a->task;
        $user = $a->user;
        $today = $user->gameDate($now);

        if ($task->scope === 'family' || $task->archived_at !== null || $task->mastered_at !== null) {
            return false;
        }
        if (! ($task->type === TaskType::Daily || ($task->type === TaskType::Habit && $task->up && ! $task->down))) {
            return false;
        }
        if ($a->value < self::MIN_VALUE) {
            return false;
        }

        $from = $today->subDays(self::WINDOW_DAYS);
        $activeSince = CarbonImmutable::parse(max($a->created_at, $task->created_at))->setTimezone($user->timezone ?: 'Europe/Berlin')->startOfDay();
        if ($activeSince->gt($from)) {
            return false;
        }

        $skipped = $this->skippedDays($user, $from, $today->subDay());
        $doneDays = TaskCompletion::where('assignment_id', $a->id)->where('direction', 'up')
            ->whereNotIn('status', [CompletionStatus::Rejected->value, CompletionStatus::Undone->value])
            ->where('created_at', '>=', $from->utc())->get(['created_at'])
            ->map(fn (TaskCompletion $c) => $user->gameDate($c->created_at)->toDateString())->unique()->flip();

        if ($task->type === TaskType::Daily) {
            $due = 0;
            $done = 0;
            for ($day = $from; $day->lt($today); $day = $day->addDay()) {
                $key = $day->toDateString();
                if (isset($skipped[$key]) || ! $task->isDueFor($day, $a->user_id)) {
                    continue;
                }
                $due++;
                $done += isset($doneDays[$key]) ? 1 : 0;
            }

            return $due > 0 && $done / $due >= self::MIN_RATE;
        }

        // Gewohnheit: je volle Woche im Fenster mindestens 5 positive Tage (ausgelassene Tage verkleinern die Woche);
        // das Tageslimit spielt keine Rolle, gezählt werden Tage
        $counted = 0;
        for ($week = $from->startOfWeek()->addWeek(); $week->addDays(6)->lt($today); $week = $week->addWeek()) {
            $days = 0;
            $skip = 0;
            for ($i = 0; $i < 7; $i++) {
                $key = $week->addDays($i)->toDateString();
                $skip += isset($skipped[$key]) ? 1 : 0;
                $days += isset($doneDays[$key]) ? 1 : 0;
            }
            if ($days < max(1, self::HABIT_DAYS_PER_WEEK - $skip)) {
                return false;
            }
            $counted++;
        }

        return $counted > 0;
    }

    /** @return array<string, true> */
    private function skippedDays(User $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $days = DayLog::where('user_id', $user->id)->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->where(fn ($q) => $q->where('low_energy', true)->orWhere('sleeping', true))->pluck('date')
            ->merge(LowEnergyDay::where('user_id', $user->id)->whereBetween('date', [$from->toDateString(), $to->toDateString()])->pluck('date'))
            ->map(fn ($d) => CarbonImmutable::parse($d)->toDateString());

        return $days->unique()->flip()->map(fn () => true)->all();
    }

    /** Täglich (Tageswechsel/Scheduler): neue Angebote markieren. */
    public function scan(User $user, ?CarbonImmutable $now = null): int
    {
        $today = $user->gameDate($now);
        $count = 0;

        $assignments = TaskAssignment::with('task', 'user')->where('user_id', $user->id)
            ->whereHas('task', fn ($q) => $q->whereNull('archived_at')->whereNull('mastered_at')->whereNull('mastery_offered_at')
                ->where('scope', '!=', 'family')->whereIn('type', [TaskType::Daily, TaskType::Habit])
                ->where(fn ($q) => $q->whereNull('mastery_offer_after')->orWhereDate('mastery_offer_after', '<=', $today->toDateString())))
            ->get();

        foreach ($assignments as $a) {
            if ($this->eligible($a, $now)) {
                $a->task->update(['mastery_offered_at' => now()]);
                $count++;
            }
        }

        return $count;
    }

    /** Meistern: verlässt die tägliche Liste, Abzeichen, Trophäenhalle, Feed. Vier Wochen lang „Klappt es noch?“. */
    public function master(User $user, Task $task, ?CarbonImmutable $now = null): void
    {
        $a = $this->offeredAssignment($user, $task);
        $today = $user->gameDate($now);

        DB::transaction(function () use ($user, $task, $a, $today) {
            $task->update([
                'mastered_at' => now(),
                'mastery_offered_at' => null,
                'mastery_check_until' => $today->addWeeks(self::CHECK_WEEKS)->toDateString(),
                'mastery_checked_on' => $today->toDateString(),
            ]);
            $a->forceFill(['completed' => false, 'reminder_question' => false])->save();

            $this->badges->award($user, "mastery:{$task->id}", 'mastery', "Meister: {$task->title}", '🎖️', ['task_id' => $task->id]);
            if (! $task->is_private) {
                $this->feed->post($user->family_id, $user, 'mastery', '🎖️', "{$user->name} hat „{$task->title}“ gemeistert! Das sitzt jetzt.");
            }
        });
    }

    /** Seltener: Die Daily wird zur Gewohnheit ohne Tagespflicht, gezählt über ein Wochenziel „5 von 7“. */
    public function lessOften(User $user, Task $task): void
    {
        $this->offeredAssignment($user, $task);

        DB::transaction(function () use ($user, $task) {
            if ($task->type === TaskType::Daily) {
                $task->update(['type' => TaskType::Habit, 'up' => true, 'down' => false, 'daily_limit' => 1, 'repeat' => null, 'is_core' => false]);
            }
            $task->update(['mastery_offered_at' => null, 'mastery_offer_after' => $user->gameDate()->addDays(self::RECHECK_DAYS)->toDateString()]);

            $this->goals->create($user, $user, [
                'type' => 'weekly',
                'title' => $task->title,
                'why' => 'Das klappt schon fast immer – jetzt reicht es 5 von 7 Tagen.',
                'icon' => '📅',
                'category' => $task->category,
                'target' => 5,
                'task_ids' => [$task->id],
                'is_private' => (bool) $task->is_private,
            ], 'mastery');
        });
    }

    /** Weitermachen: alles bleibt, die Frage kommt frühestens in 30 Tagen wieder. */
    public function keep(User $user, Task $task): void
    {
        $this->offeredAssignment($user, $task);
        $task->update(['mastery_offered_at' => null, 'mastery_offer_after' => $user->gameDate()->addDays(self::RECHECK_DAYS)->toDateString()]);
    }

    /** „Klappt es noch?“ – Nein holt die Aufgabe ohne Kommentar zurück. */
    public function stillOk(User $user, Task $task, bool $ok, ?CarbonImmutable $now = null): void
    {
        if ($task->mastered_at === null || ! $task->assignments()->where('user_id', $user->id)->exists()) {
            throw new GameException('Diese Aufgabe ist nicht gemeistert.');
        }

        $today = $user->gameDate($now);
        $ok
            ? $task->update(['mastery_checked_on' => $today->toDateString()])
            : $task->update(['mastered_at' => null, 'mastery_check_until' => null, 'mastery_checked_on' => null,
                'mastery_offer_after' => $today->addDays(self::RECHECK_DAYS)->toDateString()]);
    }

    /** Offene Angebote und fällige „Klappt es noch?“-Fragen für das Heldenpanel. */
    public function cards(User $user, ?CarbonImmutable $now = null): array
    {
        // Reine Kalenderdaten vergleichen: der Spieltag liegt in der Zeitzone der Person, Datumsspalten sind zeitzonenlos
        $today = $user->gameDate($now)->toDateString();
        $tasks = Task::whereHas('assignments', fn ($q) => $q->where('user_id', $user->id))->whereNull('archived_at')
            ->where('scope', '!=', 'family')
            ->where(fn ($q) => $q->whereNotNull('mastery_offered_at')->orWhereNotNull('mastered_at'))->get();

        return [
            'offers' => $tasks->filter(fn (Task $t) => $t->mastery_offered_at !== null && $t->mastered_at === null)
                ->map(fn (Task $t) => ['task_id' => $t->id, 'title' => $t->title, 'type' => $t->type->value])->values()->all(),
            'checks' => $tasks->filter(fn (Task $t) => $t->mastered_at !== null && $t->mastery_check_until !== null
                && $today <= $t->mastery_check_until->toDateString()
                && ($t->mastery_checked_on === null || $today >= $t->mastery_checked_on->copy()->addDays(7)->toDateString()))
                ->map(fn (Task $t) => ['task_id' => $t->id, 'title' => $t->title])->values()->all(),
            'mastered' => $tasks->filter(fn (Task $t) => $t->mastered_at !== null)
                ->map(fn (Task $t) => ['task_id' => $t->id, 'title' => $t->title, 'mastered_at' => $t->mastered_at->toDateString()])->values()->all(),
        ];
    }

    private function offeredAssignment(User $user, Task $task): TaskAssignment
    {
        $a = TaskAssignment::with('task', 'user')->where('task_id', $task->id)->where('user_id', $user->id)->first();
        if ($a === null || $task->scope === 'family') {
            throw new GameException('Diese Aufgabe kannst du nicht meistern.');
        }
        if ($task->mastery_offered_at === null && ! $this->eligible($a)) {
            throw new GameException('Diese Gewohnheit braucht noch etwas Zeit.');
        }

        return $a;
    }

    /** @return Collection<int, User> */
    public static function usersToScan(): Collection
    {
        return User::whereNotNull('family_id')->get();
    }
}
