<?php

namespace App\Domain\Goals;

use App\Domain\Achievements\HeroYearService;
use App\Domain\Badges\BadgeService;
use App\Domain\Crafting\Catalog;
use App\Domain\Events\EventService;
use App\Domain\Gameplay\Formulas;
use App\Domain\Gameplay\GameException;
use App\Domain\Gameplay\Progression;
use App\Domain\Notifications\PushNotifier;
use App\Domain\Quests\QuestService;
use App\Domain\Social\FeedService;
use App\Domain\Support\SupportService;
use App\Enums\AgeGroup;
use App\Enums\CompletionStatus;
use App\Enums\TaskType;
use App\Events\LevelUp;
use App\Models\BuildProject;
use App\Models\DayLog;
use App\Models\Goal;
use App\Models\GoalMilestone;
use App\Models\GoalWeek;
use App\Models\RecoveryQuest;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskCompletion;
use App\Models\User;
use App\Models\UserStats;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Ziele und Wochenziele (§14.1). Höchstens 3 aktive eigene Ziele je Person, jedes mit einem „Warum“.
 * Verpasste Enddaten führen zur Frage „Verlängern, anpassen oder loslassen?“, nie zu einer Strafe.
 * Wochenziele werden Sonntagabend ausgewertet; ein erreichtes Wochenziel hält die Serien der verknüpften Dailies.
 */
class GoalService
{
    public const MAX_ACTIVE = 3;

    public function __construct(
        private readonly FeedService $feed,
        private readonly PushNotifier $push,
        private readonly BadgeService $badges,
    ) {}

    /** Vorlagen aus database/content/goal_templates.json, passend zur Altersgruppe. */
    public static function templates(?User $for = null): array
    {
        static $all = null;
        $all ??= json_decode(file_get_contents(database_path('content/goal_templates.json')), true);

        return array_values(array_filter($all, fn ($t) => $for === null || in_array($for->ageGroup()->value, $t['ages'] ?? [], true)));
    }

    public static function template(string $key): ?array
    {
        return collect(self::templates())->firstWhere('key', $key);
    }

    public static function activeCount(User $owner): int
    {
        return Goal::where('user_id', $owner->id)->where('status', 'active')->where('source', 'own')->count();
    }

    /**
     * @param  array{type?: string, template_key?: ?string, title?: string, why: string, icon?: ?string, category?: ?string, target?: ?int, unit?: ?string,
     *     milestones?: list<string>, task_ids?: list<int>, ends_on?: ?string, is_private?: bool, reward_stars?: ?int, reward_wish?: ?string}  $data
     */
    public function create(User $actor, User $owner, array $data, string $source = 'own'): Goal
    {
        $this->authorizeOwnerOrParent($actor, $owner);

        if ($source === 'own' && self::activeCount($owner) >= self::MAX_ACTIVE) {
            throw new GameException('Drei Ziele gleichzeitig sind genug. Schließ erst eins ab oder leg die Idee in die Kopf-Ablage.');
        }

        $template = isset($data['template_key']) ? self::template((string) $data['template_key']) : null;
        $type = $data['type'] ?? $template['type'] ?? null;
        if (! isset(Goal::TYPES[$type])) {
            throw new GameException('Welche Art von Ziel soll es sein?');
        }

        $title = trim((string) ($data['title'] ?? $template['title'] ?? ''));
        $why = trim((string) ($data['why'] ?? ''));
        if ($title === '') {
            throw new GameException('Wie heißt dein Ziel?');
        }
        if ($why === '') {
            throw new GameException('Warum ist dir das Ziel wichtig? Schreib es in deinen Worten auf.');
        }

        $target = isset($data['target']) ? (int) $data['target'] : (isset($template['target']) ? (int) $template['target'] : null);
        $milestones = array_values(array_filter(array_map('trim', $data['milestones'] ?? $template['milestones'] ?? []), fn ($m) => $m !== ''));

        match ($type) {
            'weekly' => $target >= 1 && $target <= 7 ?: throw new GameException('Ein Wochenziel braucht 1 bis 7 Mal pro Woche.'),
            'count' => $target >= 1 && $target <= 10000 ?: throw new GameException('Wie viele sollen es werden?'),
            'milestone' => count($milestones) >= 3 && count($milestones) <= 5 ?: throw new GameException('Ein Meilenstein-Ziel hat 3 bis 5 Schritte.'),
        };

        $private = (bool) ($data['is_private'] ?? false);
        if ($private && $owner->ageGroup() === AgeGroup::Child) {
            throw new GameException('Private Ziele gibt es ab 13.');
        }

        $taskIds = $this->linkableTaskIds($owner, $data['task_ids'] ?? []);
        if ($type === 'weekly' && $taskIds === []) {
            throw new GameException('Verknüpfe das Wochenziel mit einer Aufgabe, damit es zählen kann.');
        }

        return DB::transaction(function () use ($actor, $owner, $data, $template, $type, $title, $why, $target, $milestones, $private, $taskIds, $source) {
            $goal = Goal::create([
                'family_id' => $owner->family_id,
                'user_id' => $owner->id,
                'created_by' => $actor->id,
                'type' => $type,
                'source' => $source,
                'status' => 'active',
                'template_key' => $template['key'] ?? null,
                'title' => mb_substr($title, 0, 120),
                'why' => mb_substr($why, 0, 200),
                'icon' => $data['icon'] ?? $template['icon'] ?? '🎯',
                'category' => $data['category'] ?? $template['category'] ?? null,
                'target' => $type === 'milestone' ? null : $target,
                'unit' => $type === 'count' ? ($data['unit'] ?? $template['unit'] ?? null) : null,
                'ends_on' => $data['ends_on'] ?? null,
                'is_private' => $private,
                // Sterne oder ein realer Wunsch als Zusatz-Belohnung: nur Eltern
                'reward_stars' => $actor->isParent() ? max(0, (int) ($data['reward_stars'] ?? 0)) : 0,
                'reward_wish' => $actor->isParent() ? ($data['reward_wish'] ?? null) : null,
            ]);

            foreach ($milestones as $i => $m) {
                $goal->milestones()->create(['position' => $i + 1, 'title' => mb_substr($m, 0, 120)]);
            }
            $goal->tasks()->sync($taskIds);

            if (! $private) {
                $this->feed->post($owner->family_id, $owner, 'goal_started', $goal->icon, "{$owner->name} hat sich ein Ziel gesetzt: „{$goal->title}“.");
            }

            return $goal;
        });
    }

    /** Titel, Warum, Ziel, Enddatum, Verknüpfungen ändern. Ein abgelaufenes Ziel wird mit neuem Enddatum wieder aktiv. */
    public function update(User $actor, Goal $goal, array $data): Goal
    {
        $this->authorizeOwnerOrParent($actor, $goal->user);
        if (in_array($goal->status, ['reached', 'released'], true)) {
            throw new GameException('Dieses Ziel ist schon abgeschlossen.');
        }

        if (array_key_exists('title', $data) && trim((string) $data['title']) !== '') {
            $goal->title = mb_substr(trim($data['title']), 0, 120);
        }
        if (array_key_exists('why', $data) && trim((string) $data['why']) !== '') {
            $goal->why = mb_substr(trim($data['why']), 0, 200);
        }
        if (array_key_exists('target', $data) && $goal->type !== 'milestone') {
            $target = (int) $data['target'];
            $max = $goal->type === 'weekly' ? 7 : 10000;
            if ($target < 1 || $target > $max) {
                throw new GameException('Diese Zahl passt nicht.');
            }
            $goal->target = $target;
        }
        if (array_key_exists('ends_on', $data)) {
            $goal->ends_on = $data['ends_on'];
            if ($goal->status === 'expired' && ($goal->ends_on === null || $goal->ends_on->gte($goal->user->gameDate()))) {
                $goal->status = 'active';
            }
        }
        if (array_key_exists('task_ids', $data)) {
            $ids = $this->linkableTaskIds($goal->user, $data['task_ids'] ?? []);
            if ($goal->type === 'weekly' && $ids === []) {
                throw new GameException('Ein Wochenziel braucht eine Aufgabe.');
            }
            $goal->tasks()->sync($ids);
        }
        if ($actor->isParent() && array_key_exists('reward_stars', $data)) {
            $goal->reward_stars = max(0, (int) $data['reward_stars']);
        }
        if ($actor->isParent() && array_key_exists('reward_wish', $data)) {
            $goal->reward_wish = $data['reward_wish'];
        }
        $goal->save();

        // Ein angepasstes Zähl-Ziel kann jetzt schon erreicht sein
        if ($goal->type === 'count' && $goal->isActive()) {
            $this->checkReached($goal);
        }

        return $goal;
    }

    /** Loslassen: ohne Strafe, ohne Kommentar. */
    public function release(User $actor, Goal $goal): void
    {
        $this->authorizeOwnerOrParent($actor, $goal->user);
        if ($goal->status === 'reached') {
            throw new GameException('Dieses Ziel ist schon erreicht.');
        }
        $goal->update(['status' => 'released']);
    }

    /** Zähl-Ziel von Hand weiterzählen (z. B. ein Buch fertig gelesen). */
    public function addCount(User $actor, Goal $goal, int $amount = 1): Goal
    {
        $this->authorizeOwnerOrParent($actor, $goal->user);
        if ($goal->type !== 'count' || ! $goal->isActive()) {
            throw new GameException('Hier kann man nicht zählen.');
        }
        if ($amount === 0 || abs($amount) > 100) {
            throw new GameException('Diese Zahl passt nicht.');
        }

        $goal->manual_progress = max(0, $goal->manual_progress + $amount);
        $goal->save();
        $this->checkReached($goal);

        return $goal->refresh();
    }

    /**
     * Meilenstein abhaken. Bei Kindern unter 13 bestätigt ein Elternteil: Das Kind meldet, die Eltern bekommen eine Freigabe.
     *
     * @return string done | requested
     */
    public function completeMilestone(User $actor, Goal $goal, GoalMilestone $milestone): string
    {
        $this->authorizeOwnerOrParent($actor, $goal->user);
        $milestone->refresh();
        if ($milestone->goal_id !== $goal->id || $goal->type !== 'milestone') {
            throw new GameException('Diesen Schritt gibt es hier nicht.');
        }
        if (! $goal->isActive()) {
            throw new GameException('Dieses Ziel ist nicht mehr aktiv.');
        }
        if ($milestone->done_at !== null) {
            throw new GameException('Diesen Schritt hast du schon geschafft.');
        }

        $owner = $goal->user;
        if (! $actor->isParent() && $owner->ageGroup() === AgeGroup::Child) {
            if ($milestone->requested_at === null) {
                $milestone->update(['requested_at' => now()]);
                $this->push->notify(PushNotifier::parentsOf($owner->family_id), 'goal_confirm', ['goal_id' => $goal->id]);
            }

            return 'requested';
        }

        $this->markMilestoneDone($goal, $milestone, $actor);

        return 'done';
    }

    /** Eltern: gemeldeten Meilenstein noch nicht bestätigen (freundlich zurück an das Kind). */
    public function declineMilestone(User $parent, Goal $goal, GoalMilestone $milestone): void
    {
        if (! $parent->isParent() || ! $parent->sameFamilyAs($goal->user) || $milestone->goal_id !== $goal->id) {
            throw new GameException('Das dürfen nur Eltern aus der Familie.');
        }
        $milestone->update(['requested_at' => null]);
    }

    /** „Ich helfe dir“: sieht den Fortschritt und bekommt einen Hinweis bei jedem Meilenstein. */
    public function support(User $actor, Goal $goal, bool $on = true): void
    {
        if (! $actor->sameFamilyAs($goal->user) || $actor->id === $goal->user_id) {
            throw new GameException('Unterstützen kann man nur andere aus der Familie.');
        }
        if ($goal->is_private) {
            throw new GameException('Dieses Ziel ist privat.');
        }

        if ($on) {
            $goal->supporters()->syncWithoutDetaching([$actor->id]);
            $this->feed->post($goal->family_id, $actor, 'goal_support', '🤝', "{$actor->name} hilft {$goal->user->name} beim Ziel „{$goal->title}“.");
        } else {
            $goal->supporters()->detach($actor->id);
        }
    }

    /** Erledigte Aufgabe: verknüpfte Zähl-Ziele prüfen (Wochenziele zählen bei der Wochenauswertung). */
    public function onCompletion(TaskCompletion $completion): void
    {
        if ($completion->direction !== 'up' || in_array($completion->status, [CompletionStatus::Rejected, CompletionStatus::Undone], true)) {
            return;
        }

        Goal::where('user_id', $completion->user_id)->where('status', 'active')->where('type', 'count')
            ->whereHas('tasks', fn ($q) => $q->whereKey($completion->task_id))
            ->get()->each(fn (Goal $g) => $this->checkReached($g));
    }

    /**
     * Fortschritt eines Ziels. Für Wochenziele die laufende Woche (Mo–So in der Zeitzone der Person):
     * erledigte Tage der verknüpften Aufgaben; Energiespar- und Gasthaus-Tage verkleinern das Ziel.
     *
     * @return array{done: int, target: int, percent: int, skipped?: int, week_start?: string}
     */
    public function progress(Goal $goal, ?CarbonImmutable $now = null): array
    {
        return match ($goal->type) {
            'weekly' => $this->weekProgress($goal, $goal->user->gameDate($now)->startOfWeek()),
            'count' => $this->countProgress($goal),
            default => (function () use ($goal) {
                $total = $goal->milestones->count();
                $done = $goal->milestones->whereNotNull('done_at')->count();

                return ['done' => $done, 'target' => $total, 'percent' => $total ? (int) floor(100 * $done / $total) : 0];
            })(),
        };
    }

    /** @return array{done: int, target: int, percent: int, skipped: int, week_start: string} */
    public function weekProgress(Goal $goal, CarbonImmutable $weekStart): array
    {
        $owner = $goal->user;
        $weekEnd = $weekStart->addDays(6);
        $today = $owner->gameDate();
        $taskIds = $goal->tasks()->pluck('tasks.id');

        $days = TaskCompletion::where('user_id', $owner->id)->whereIn('task_id', $taskIds)->where('direction', 'up')
            ->whereNotIn('status', [CompletionStatus::Rejected->value, CompletionStatus::Undone->value])
            // Spieltage beginnen um day_start Uhr in der Zeitzone der Person ($weekStart liegt schon in dieser Zeitzone)
            ->where('created_at', '>=', $weekStart->addHours($owner->day_start ?? 0)->utc())
            ->where('created_at', '<', $weekEnd->addDay()->addHours($owner->day_start ?? 0)->utc())
            ->get(['created_at'])
            ->map(fn (TaskCompletion $c) => $owner->gameDate($c->created_at)->toDateString())
            ->unique()->count();

        $skipped = $this->skippedDays($owner, $weekStart, $weekEnd->min($today));
        $target = max(1, (int) $goal->target - $skipped);

        return [
            'done' => $days,
            'target' => $target,
            'percent' => min(100, (int) floor(100 * $days / $target)),
            'skipped' => $skipped,
            'week_start' => $weekStart->toDateString(),
        ];
    }

    /** Energiespar-Tage und Gasthaus-Tage (DayLog.sleeping) zählen als „ausgelassen“. */
    private function skippedDays(User $owner, CarbonImmutable $from, CarbonImmutable $to): int
    {
        if ($to->lt($from)) {
            return 0;
        }

        $logged = DayLog::where('user_id', $owner->id)->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->where(fn ($q) => $q->where('low_energy', true)->orWhere('sleeping', true))
            ->pluck('date')->map(fn ($d) => CarbonImmutable::parse($d)->toDateString());

        $skipped = $logged->all();
        for ($day = $from; $day->lte($to); $day = $day->addDay()) {
            if (SupportService::isLowEnergy($owner, $day)) {
                $skipped[] = $day->toDateString();
            }
        }

        return count(array_unique($skipped));
    }

    /** @return array{done: int, target: int, percent: int} */
    private function countProgress(Goal $goal): array
    {
        $linked = TaskCompletion::where('user_id', $goal->user_id)->whereIn('task_id', $goal->tasks()->pluck('tasks.id'))
            ->where('direction', 'up')->whereNotIn('status', [CompletionStatus::Rejected->value, CompletionStatus::Undone->value])
            ->where('created_at', '>=', $goal->created_at)->count();
        $done = $goal->manual_progress + $linked;
        $target = max(1, (int) $goal->target);

        return ['done' => $done, 'target' => $target, 'percent' => min(100, (int) floor(100 * $done / $target))];
    }

    private function checkReached(Goal $goal): void
    {
        if ($goal->isActive() && $goal->type === 'count' && $this->countProgress($goal)['done'] >= (int) $goal->target) {
            $this->reach($goal);
        }
    }

    /**
     * Wochenauswertung (Sonntag 23:30 in der Zeitzone der Person, holt verpasste Läufe für die Vorwoche nach).
     * Erreichtes Wochenziel: Belohnung einer schweren Aufgabe, beim ersten Mal Abzeichen, Serien der verknüpften Dailies bleiben.
     *
     * @return int ausgewertete Wochen
     */
    public function evaluateWeeks(User $user, ?CarbonImmutable $now = null): int
    {
        $local = CarbonImmutable::instance($now ?? now())->setTimezone($user->timezone ?: 'Europe/Berlin');
        $thisWeek = $user->gameDate($now)->startOfWeek();
        $weeks = [$thisWeek->subWeek()];
        if ($local->dayOfWeekIso === 7 && $local->format('H:i') >= '23:30') {
            $weeks[] = $thisWeek;
        }

        $count = 0;
        $goals = Goal::with('user')->where('user_id', $user->id)->where('status', 'active')->where('type', 'weekly')->get();
        foreach ($goals as $goal) {
            foreach ($weeks as $weekStart) {
                // Nur Wochen, in denen es das Ziel schon gab
                if ($goal->created_at->copy()->setTimezone($user->timezone ?: 'Europe/Berlin')->startOfDay()->gt($weekStart->addDays(6)->endOfDay())) {
                    continue;
                }
                if (GoalWeek::where('goal_id', $goal->id)->whereDate('week_start', $weekStart->toDateString())->exists()) {
                    continue;
                }
                $this->evaluateWeek($goal, $weekStart);
                $count++;
            }
        }

        return $count;
    }

    public function evaluateWeek(Goal $goal, CarbonImmutable $weekStart): GoalWeek
    {
        $p = $this->weekProgress($goal, $weekStart);
        $reached = $p['done'] >= $p['target'];

        return DB::transaction(function () use ($goal, $weekStart, $p, $reached) {
            $week = GoalWeek::create(['goal_id' => $goal->id, 'week_start' => $weekStart->toDateString(), 'done' => $p['done'], 'target' => $p['target'], 'reached' => $reached]);
            if (! $reached) {
                return $week;
            }

            $goal->increment('weeks_reached');
            $this->rewardHardTask($goal->user);
            $this->keepStreaks($goal, $weekStart);

            if ($goal->weeks_reached === 1) {
                $this->badges->award($goal->user, "goal:{$goal->id}", 'goal', $goal->title, $goal->icon, ['goal_id' => $goal->id]);
            }
            if (! $goal->is_private) {
                $this->feed->post($goal->family_id, $goal->user, 'goal_week', $goal->icon, "{$goal->user->name} hat das Wochenziel „{$goal->title}“ geschafft ({$p['done']} von {$p['target']}).");
            }
            $this->notifySupporters($goal);

            return $week;
        });
    }

    /** Wochen statt täglicher Perfektion: pausierte Serien verknüpfter Dailies dieser Woche wiederherstellen. */
    private function keepStreaks(Goal $goal, CarbonImmutable $weekStart): void
    {
        $assignments = TaskAssignment::where('user_id', $goal->user_id)
            ->whereIn('task_id', $goal->tasks()->where('type', TaskType::Daily)->pluck('tasks.id'))
            ->whereNotNull('streak_paused_at')->get();

        foreach ($assignments as $a) {
            $quest = RecoveryQuest::where('assignment_id', $a->id)->where('status', 'active')->latest('id')->first();
            if ($quest !== null) {
                $quest->update(['status' => 'restored', 'done' => $quest->needed]);
                $a->streak = max($a->streak, $quest->streak_to_restore);
            }
            $a->streak_paused_at = null;
            $a->save();
        }
    }

    /** Abgelaufene Enddaten: Status „expired“ – die Karte fragt dann „Verlängern, anpassen oder loslassen?“. */
    public function expireDue(User $user, ?CarbonImmutable $now = null): int
    {
        return Goal::where('user_id', $user->id)->where('status', 'active')->whereNotNull('ends_on')
            ->whereDate('ends_on', '<', $user->gameDate($now)->toDateString())
            ->update(['status' => 'expired']);
    }

    private function markMilestoneDone(Goal $goal, GoalMilestone $milestone, User $actor): void
    {
        DB::transaction(function () use ($goal, $milestone, $actor) {
            $milestone = GoalMilestone::lockForUpdate()->findOrFail($milestone->id);
            if ($milestone->done_at !== null) {
                return;
            }
            $milestone->update(['done_at' => now(), 'requested_at' => null, 'confirmed_by' => $actor->isParent() ? $actor->id : null]);

            $this->rewardHardTask($goal->user);
            if (! $goal->is_private) {
                $this->feed->post($goal->family_id, $goal->user, 'goal_milestone', '🚩', "{$goal->user->name} hat einen Meilenstein geschafft: „{$milestone->title}“ ({$goal->title}).");
            }
            $this->notifySupporters($goal);

            if ($goal->milestones()->whereNull('done_at')->doesntExist()) {
                $this->reach($goal);
            }
        });
    }

    /** Ziel erreicht: Abzeichen, Trophäenhalle, Feed, ggf. Sterne der Eltern. Genau einmal. */
    private function reach(Goal $goal): void
    {
        DB::transaction(function () use ($goal) {
            $goal = Goal::with('user')->lockForUpdate()->findOrFail($goal->id);
            if ($goal->status !== 'active') {
                return;
            }
            $goal->update(['status' => 'reached', 'reached_at' => now()]);

            $this->badges->award($goal->user, "goal:{$goal->id}", 'goal', $goal->title, $goal->icon, ['goal_id' => $goal->id]);
            if ($goal->reward_stars > 0) {
                UserStats::whereKey($goal->user_id)->increment('stars', $goal->reward_stars);
            }

            $wish = $goal->reward_wish ? " Belohnung: {$goal->reward_wish}" : '';
            $this->feed->post($goal->family_id, $goal->user, 'goal_reached', '🏆',
                $goal->is_private ? "{$goal->user->name} hat ein Ziel erreicht!" : "{$goal->user->name} hat das Ziel „{$goal->title}“ erreicht!{$wish}");
            $this->push->notify(PushNotifier::parentsOf($goal->family_id)->where('id', '!=', $goal->user_id), 'goal_reached', ['goal_id' => $goal->id]);
            $this->notifySupporters($goal);

            // Heldenjahr (§14.7), falls vorhanden: Ziele bringen Jahrespunkte
            if (class_exists(HeroYearService::class)) {
                app(HeroYearService::class)->addPoints($goal->user, 20, 'goal');
            }
        });
    }

    /** Gold und XP wie eine erledigte schwere Aufgabe (Schwierigkeit 2 bei neutralem Wert). */
    private function rewardHardTask(User $user): void
    {
        $stats = UserStats::lockForUpdate()->findOrFail($user->id);
        $delta = Formulas::delta(0);
        $levelUps = Progression::gainXp($stats, Formulas::xp($delta, 2));
        Progression::changeGold($stats, Formulas::gold($delta, 2));
        $stats->save();

        if ($levelUps > 0) {
            event(new LevelUp($user, $stats->level));
        }
    }

    private function notifySupporters(Goal $goal): void
    {
        $supporters = $goal->supporters()->get();
        if ($supporters->isNotEmpty()) {
            $this->push->notify($supporters, 'goal_progress', ['goal_id' => $goal->id]);
        }
    }

    /** Nur eigene, nicht archivierte Aufgaben der Person (Dailies, Gewohnheiten, To-dos). */
    private function linkableTaskIds(User $owner, array $ids): array
    {
        $ids = array_map('intval', $ids);
        if ($ids === []) {
            return [];
        }

        return Task::whereIn('id', $ids)->whereNull('archived_at')
            ->whereIn('type', [TaskType::Daily, TaskType::Habit, TaskType::Todo])
            ->whereHas('assignments', fn ($q) => $q->where('user_id', $owner->id))
            ->pluck('id')->all();
    }

    private function authorizeOwnerOrParent(User $actor, User $owner): void
    {
        if ($actor->id !== $owner->id && ! ($actor->isParent() && $actor->sameFamilyAs($owner))) {
            throw new GameException('Das ist nicht dein Ziel.');
        }
    }

    // ---------- Darstellung ----------

    public function present(Goal $goal, User $viewer): array
    {
        $own = $viewer->id === $goal->user_id;
        $supporter = $goal->relationLoaded('supporters') ? $goal->supporters->contains('id', $viewer->id) : $goal->supporters()->whereKey($viewer->id)->exists();

        return [
            'id' => $goal->id,
            'user_id' => $goal->user_id,
            'owner' => $goal->user->name,
            'type' => $goal->type,
            'type_label' => Goal::TYPES[$goal->type],
            'source' => $goal->source,
            'title' => $goal->title,
            'why' => $goal->why,
            'icon' => $goal->icon,
            'category' => $goal->category,
            'target' => $goal->target,
            'unit' => $goal->unit,
            'status' => $goal->status,
            'is_private' => $goal->is_private,
            'ends_on' => $goal->ends_on?->toDateString(),
            'reward_stars' => $goal->reward_stars,
            'reward_wish' => $goal->reward_wish,
            'weeks_reached' => $goal->weeks_reached,
            'reached_at' => $goal->reached_at?->toDateString(),
            'progress' => $this->progress($goal),
            'milestones' => $goal->milestones->map(fn (GoalMilestone $m) => [
                'id' => $m->id, 'title' => $m->title, 'done' => $m->done_at !== null, 'requested' => $m->requested_at !== null,
                'done_at' => $m->done_at?->toDateString(),
            ])->values(),
            'tasks' => $goal->tasks->map(fn (Task $t) => ['id' => $t->id, 'title' => $t->title, 'type' => $t->type->value])->values(),
            'supporters' => $goal->supporters->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->values(),
            'can_edit' => $own || ($viewer->isParent() && $viewer->sameFamilyAs($goal->user)),
            'can_support' => ! $own && ! $goal->is_private && $viewer->sameFamilyAs($goal->user),
            'is_supporter' => $supporter,
        ];
    }

    /**
     * Ziele einer Person aus Sicht von $viewer. Private Teen-Ziele sehen andere nur als Anzahl (§3.7).
     *
     * @return array{goals: list<array>, private_count: int, active_count: int, max_active: int}
     */
    public function forUser(User $owner, User $viewer, bool $withClosed = false): array
    {
        $goals = Goal::with('user', 'milestones', 'tasks', 'supporters')->where('user_id', $owner->id)
            ->when(! $withClosed, fn ($q) => $q->whereIn('status', ['active', 'expired']))
            ->latest('id')->get();

        $visible = $goals->filter(fn (Goal $g) => ! $g->is_private || $viewer->id === $owner->id);

        return [
            'goals' => $visible->map(fn (Goal $g) => $this->present($g, $viewer))->values()->all(),
            'private_count' => $goals->count() - $visible->count(),
            'active_count' => self::activeCount($owner),
            'max_active' => self::MAX_ACTIVE,
        ];
    }

    /** Gemeinsame Ziele der Familie am selben Ort: Bauprojekte, Familien-Quest, Event-Ziele. */
    public static function familyGoals(User $viewer): array
    {
        if (! $viewer->family_id) {
            return [];
        }

        $goals = BuildProject::where('family_id', $viewer->family_id)->whereIn('status', ['collecting', 'building'])->get()
            ->map(function (BuildProject $b) {
                $project = Catalog::project($b->project_key);
                $needed = (int) $b->requirements['gold'] + array_sum((array) $b->requirements['items']);
                $have = (int) (($b->progress ?? [])['gold'] ?? 0) + array_sum((array) (($b->progress ?? [])['items'] ?? []));

                return [
                    'kind' => 'build', 'title' => $project['name'], 'icon' => $project['icon'] ?? '🏗️', 'href' => '/housing',
                    'percent' => $b->status === 'building' ? 100 : min(100, (int) floor(100 * $have / max(1, $needed))),
                    'note' => $b->status === 'building' ? 'wird gebaut' : ($b->reward_text ?: null),
                ];
            });

        $quest = QuestService::present(app(QuestService::class)->activeFamily($viewer->family_id));
        if ($quest !== null) {
            $goals->push(['kind' => 'quest', 'title' => $quest['name'], 'icon' => $quest['icon'] ?? '⚔️', 'href' => '/quests',
                'percent' => $quest['goal'] > 0 ? min(100, (int) floor(100 * $quest['progress'] / $quest['goal'])) : 0, 'note' => $quest['reward_text'] ?? null]);
        }

        foreach (EventService::summary($viewer) as $event) {
            if ($event['goal_percent'] !== null) {
                $goals->push(['kind' => 'event', 'title' => $event['name'], 'icon' => $event['icon'], 'href' => "/events/{$event['id']}", 'percent' => $event['goal_percent'], 'note' => null]);
            }
        }

        return $goals->values()->all();
    }

    /** Für das Heldenpanel: aktive eigene Ziele kompakt plus Fragen (abgelaufen, Bestätigung). */
    public function boardSummary(User $user): array
    {
        $goals = Goal::with('user', 'milestones', 'tasks', 'supporters')->where('user_id', $user->id)->whereIn('status', ['active', 'expired'])->latest('id')->get();

        return [
            'active' => $goals->where('status', 'active')->map(fn (Goal $g) => [
                'id' => $g->id, 'title' => $g->title, 'icon' => $g->icon, 'type' => $g->type, 'progress' => $this->progress($g),
                'next_milestone' => $g->milestones->firstWhere('done_at', null)?->title,
            ])->values()->all(),
            'expired' => $goals->where('status', 'expired')->map(fn (Goal $g) => ['id' => $g->id, 'title' => $g->title, 'icon' => $g->icon])->values()->all(),
            'to_confirm' => $user->isParent() ? self::pendingConfirmations($user) : [],
        ];
    }

    /** Eltern: gemeldete Meilensteine der Kinder. */
    public static function pendingConfirmations(User $parent): array
    {
        return GoalMilestone::with('goal.user')->whereNotNull('requested_at')->whereNull('done_at')
            ->whereHas('goal', fn ($q) => $q->where('family_id', $parent->family_id)->where('status', 'active'))
            ->get()->map(fn (GoalMilestone $m) => [
                'goal_id' => $m->goal_id, 'milestone_id' => $m->id, 'title' => $m->title, 'goal' => $m->goal->title, 'icon' => $m->goal->icon, 'owner' => $m->goal->user->name,
            ])->values()->all();
    }

    /** Alle Familienmitglieder mit aktiven Wochenzielen (für den Scheduler). */
    public static function usersWithWeeklyGoals(): Collection
    {
        return User::whereIn('id', Goal::where('status', 'active')->where('type', 'weekly')->select('user_id'))->get();
    }
}
