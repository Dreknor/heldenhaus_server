<?php

namespace App\Domain\Goals;

use App\Domain\Badges\BadgeService;
use App\Domain\Gameplay\GameException;
use App\Domain\Notifications\PushNotifier;
use App\Domain\Quests\QuestService;
use App\Domain\Social\FeedService;
use App\Enums\TaskType;
use App\Models\BuildContribution;
use App\Models\BuildProject;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskCompletion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Belohnungen ausschleichen – „Ehrensache“ (§14.3). Nie automatisch: Die App schlägt den Eltern vor, die Sterne einer
 * Familienaufgabe in Stufen zu senken (−25 % alle 2 Wochen bis 0), sobald eine Person sie 30-mal bestätigt erledigt hat
 * (≥ 80 % im Zeitraum). Das Kind wird vorher informiert. Bei 0 Sternen gibt es weiter XP und Gold, ein „Danke“ der Familie
 * und Familien-Ehre für das laufende Bauprojekt bzw. die Familien-Quest.
 */
class StarFadeService
{
    public const MIN_CONFIRMED = 30;

    public const MIN_RATE = 0.8;

    public const STEP_WEEKS = 2;

    public const STEPS = 4; // 100 % → 75 → 50 → 25 → 0

    public const HONOR_POINTS = 3;

    /** Bestätigt erledigt: von Eltern bestätigt, automatisch bestätigt oder ohne Freigabe nötig. */
    public const CONFIRMED = ['approved', 'auto', 'none'];

    public function __construct(
        private readonly FeedService $feed,
        private readonly PushNotifier $push,
        private readonly BadgeService $badges,
    ) {}

    /**
     * Vorschläge für die Eltern einer Familie.
     *
     * @return list<array{task_id: int, title: string, stars: int, member: string, user_id: int, confirmed: int}>
     */
    public function suggestions(int $familyId): array
    {
        $tasks = Task::with('assignments.user')->where('family_id', $familyId)->where('scope', 'family')->whereNull('archived_at')
            ->where('star_reward', '>', 0)->whereNull('star_fade_base')->whereNull('star_fade_offered_at')->get();

        $result = [];
        foreach ($tasks as $task) {
            foreach ($task->assignments as $a) {
                $confirmed = $this->confirmedCount($a);
                if ($confirmed >= self::MIN_CONFIRMED && $this->rate($a) >= self::MIN_RATE) {
                    $result[] = ['task_id' => $task->id, 'title' => $task->title, 'stars' => (int) $task->star_reward,
                        'member' => $a->user->name, 'user_id' => $a->user_id, 'confirmed' => $confirmed];
                    break;
                }
            }
        }

        return $result;
    }

    private function confirmedCount(TaskAssignment $a): int
    {
        return TaskCompletion::where('assignment_id', $a->id)->where('direction', 'up')
            ->whereIn('status', self::CONFIRMED)->count();
    }

    /** Anteil erledigter Tage seit der ersten bestätigten Erledigung (Dailies); bei To-dos/Börse zählt nur die Anzahl. */
    private function rate(TaskAssignment $a): float
    {
        $task = $a->task;
        if ($task->type !== TaskType::Daily) {
            return 1.0;
        }

        $user = $a->user;
        $first = TaskCompletion::where('assignment_id', $a->id)->where('direction', 'up')->min('created_at');
        if ($first === null) {
            return 0.0;
        }

        $done = TaskCompletion::where('assignment_id', $a->id)->where('direction', 'up')
            ->whereIn('status', self::CONFIRMED)->get(['created_at'])
            ->map(fn (TaskCompletion $c) => $user->gameDate($c->created_at)->toDateString())->unique()->flip();

        $today = $user->gameDate();
        $due = 0;
        $hit = 0;
        for ($day = $user->gameDate(CarbonImmutable::parse($first)); $day->lt($today); $day = $day->addDay()) {
            if ($task->isDueFor($day, $a->user_id)) {
                $due++;
                $hit += isset($done[$day->toDateString()]) ? 1 : 0;
            }
        }

        return $due > 0 ? $hit / $due : 1.0;
    }

    /** Eltern entscheiden: Ausschleichen beginnt am nächsten Montag; die Kinder erfahren es vorher. */
    public function start(User $parent, Task $task): Task
    {
        $this->authorize($parent, $task);
        if ($task->star_fade_base !== null) {
            throw new GameException('Diese Aufgabe wird schon Ehrensache.');
        }
        if ((int) $task->star_reward <= 0) {
            throw new GameException('Diese Aufgabe bringt keine Sterne.');
        }

        $from = $parent->gameDate()->next(CarbonImmutable::MONDAY);
        $task->update(['star_fade_base' => $task->star_reward, 'star_fade_from' => $from->toDateString(), 'star_fade_step' => 0, 'star_fade_offered_at' => now()]);

        $members = $task->assignments()->with('user')->get()->pluck('user');
        $this->push->notify($members, 'star_fade', ['task_id' => $task->id]);
        $this->feed->post($task->family_id, null, 'star_fade', '🤝',
            "Ab {$from->format('d.m.')} gibt es für „{$task->title}“ Schritt für Schritt weniger Sterne – am Ende wird sie Ehrensache. Dafür gibt es das Abzeichen „Verlässlich“.");

        return $task;
    }

    /** Eltern: „Nicht jetzt“ – der Vorschlag verschwindet. */
    public function dismiss(User $parent, Task $task): void
    {
        $this->authorize($parent, $task);
        $task->update(['star_fade_offered_at' => now()]);
    }

    /** Eltern: Ausschleichen zurücknehmen, die ursprünglichen Sterne gelten wieder. */
    public function stop(User $parent, Task $task): void
    {
        $this->authorize($parent, $task);
        if ($task->star_fade_base === null) {
            return;
        }
        $task->update(['star_reward' => $task->star_fade_base, 'star_fade_base' => null, 'star_fade_from' => null, 'star_fade_step' => 0]);
    }

    /** Täglich: aktuelle Stufe aus dem Datum berechnen; bei 0 wird die Aufgabe Ehrensache. */
    public function apply(?CarbonImmutable $now = null): int
    {
        $changed = 0;
        $tasks = Task::with('assignments.user')->whereNotNull('star_fade_base')->whereNotNull('star_fade_from')->whereNull('archived_at')->get();

        foreach ($tasks as $task) {
            $today = CarbonImmutable::instance($now ?? now())->startOfDay();
            $from = CarbonImmutable::parse($task->star_fade_from->format('Y-m-d'));
            $step = $today->lt($from) ? 0 : min(self::STEPS, intdiv((int) $from->diffInDays($today), self::STEP_WEEKS * 7) + 1);
            if ($step === (int) $task->star_fade_step) {
                continue;
            }

            $stars = (int) round($task->star_fade_base * (1 - $step / self::STEPS));
            $task->update(['star_fade_step' => $step, 'star_reward' => $stars]);
            $changed++;

            if ($step === self::STEPS - 1) {
                // Vorher informieren: in zwei Wochen ist es so weit
                $this->push->notify($task->assignments->pluck('user'), 'star_fade', ['task_id' => $task->id]);
            }
            if ($step === self::STEPS) {
                foreach ($task->assignments as $a) {
                    $this->badges->award($a->user, "reliable:{$task->id}", 'reliable', "Verlässlich: {$task->title}", '🤝', ['task_id' => $task->id]);
                }
                $this->feed->post($task->family_id, null, 'honor', '💛', "„{$task->title}“ ist jetzt Ehrensache. Danke, dass ihr euch darauf verlassen könnt!");
            }
        }

        return $changed;
    }

    /** Erledigte (bestätigte) Ehrensache: „Danke“ im Feed und Familien-Ehre für das gemeinsame Ziel. */
    public function onConfirmed(TaskCompletion $completion): void
    {
        $task = $completion->task;
        if ($task === null || $completion->direction !== 'up' || ! $task->isHonor()
            || ! in_array($completion->status->value, self::CONFIRMED, true)) {
            return;
        }

        $user = $completion->user;
        DB::transaction(function () use ($task, $user) {
            $build = BuildProject::where('family_id', $task->family_id)->where('status', 'collecting')->lockForUpdate()->oldest('id')->first();
            if ($build !== null) {
                // Ehre zählt wie Gold für das Bauprojekt, ohne jemandem Gold abzuziehen
                $progress = $build->progress ?? ['items' => [], 'gold' => 0];
                $missing = (int) $build->requirements['gold'] - (int) ($progress['gold'] ?? 0);
                if ($missing > 0) {
                    $points = min($missing, self::HONOR_POINTS);
                    $progress['gold'] = (int) ($progress['gold'] ?? 0) + $points;
                    $build->progress = $progress;
                    $build->save();
                    BuildContribution::create(['build_project_id' => $build->id, 'user_id' => $user->id, 'item_key' => 'honor', 'amount' => $points]);

                    return;
                }
            }

            $quests = app(QuestService::class);
            if (($quest = $quests->activeFamily($task->family_id)) !== null) {
                $quests->damage($quest, $user, self::HONOR_POINTS);
            }
        });

        $this->feed->post($task->family_id, $user, 'honor_thanks', '💛', "Danke, {$user->name}! „{$task->title}“ – Ehrensache.");
    }

    /** Für die Familienaufgaben-Ansicht der Eltern. */
    public static function present(Task $task): ?array
    {
        if ($task->star_fade_base === null) {
            return null;
        }

        return [
            'base' => $task->star_fade_base,
            'from' => $task->star_fade_from?->toDateString(),
            'step' => (int) $task->star_fade_step,
            'steps' => self::STEPS,
            'honor' => $task->isHonor(),
        ];
    }

    private function authorize(User $parent, Task $task): void
    {
        if (! $parent->isParent() || $parent->family_id !== $task->family_id || $task->scope !== 'family') {
            throw new GameException('Das entscheiden die Eltern bei Familienaufgaben.');
        }
    }
}
