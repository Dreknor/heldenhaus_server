<?php

namespace App\Domain\Insights;

use App\Domain\Achievements\AchievementService;
use App\Domain\Gameplay\GameException;
use App\Domain\Social\FeedService;
use App\Models\DayLog;
use App\Models\Quest;
use App\Models\QuestContribution;
use App\Models\Review;
use App\Models\TaskAssignment;
use App\Models\User;
use App\Models\UserAchievement;
use App\Models\UserBadge;
use App\Models\UserPet;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Persönlicher Rückblick (Konzept §14.5): Woche (Montag 05:00), Monat (1. des Monats 05:00) und
 * Jahr (Neujahr 05:00), jeweils in der Zeitzone der Familie. Verglichen wird nur mit sich selbst,
 * und nie mit einem Minus: Gab es weniger als in der Vorwoche, nennt der Rückblick das Geschaffte.
 *
 * Private Inhalte (private Aufgaben und Ziele von Teens, §3.7) tragen das Merkmal „private“ und werden
 * für alle außer der Person selbst auf eine Anzahl reduziert (present()).
 */
class ReviewService
{
    public const KINDS = ['week', 'month', 'year'];

    /** Rückblicke erscheinen so viele Stunden nach Beginn des neuen Zeitraums. */
    public const RELEASE_HOUR = 5;

    private const WEEKDAYS = [1 => 'Montag', 2 => 'Dienstag', 3 => 'Mittwoch', 4 => 'Donnerstag', 5 => 'Freitag', 6 => 'Samstag', 7 => 'Sonntag'];

    private const MONTHS = [1 => 'Januar', 2 => 'Februar', 3 => 'März', 4 => 'April', 5 => 'Mai', 6 => 'Juni', 7 => 'Juli', 8 => 'August', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Dezember'];

    public function __construct(
        private readonly ActivityScores $scores,
        private readonly FeedService $feed,
    ) {}

    public static function timezone(User $user): string
    {
        return $user->family?->timezone ?: ($user->timezone ?: 'Europe/Berlin');
    }

    /** Beginn des zuletzt abgeschlossenen Zeitraums. */
    public static function latestStart(User $user, string $kind, ?CarbonImmutable $now = null): CarbonImmutable
    {
        $local = ($now ?? CarbonImmutable::now())->setTimezone(self::timezone($user))->subHours(self::RELEASE_HOUR);

        return match ($kind) {
            'week' => $local->startOfWeek()->subWeek()->startOfDay(),
            'month' => $local->startOfMonth()->subMonth()->startOfDay(),
            'year' => $local->startOfYear()->subYear()->startOfDay(),
            default => throw new GameException('Diesen Rückblick gibt es nicht.'),
        };
    }

    public static function periodEnd(CarbonImmutable $start, string $kind): CarbonImmutable
    {
        return match ($kind) {
            'week' => $start->addDays(6),
            'month' => $start->endOfMonth()->startOfDay(),
            'year' => $start->endOfYear()->startOfDay(),
        };
    }

    /** Beginn des Zeitraums, in dem ein Datum liegt. */
    public static function startOf(CarbonImmutable $date, string $kind): CarbonImmutable
    {
        return match ($kind) {
            'week' => $date->startOfWeek()->startOfDay(),
            'month' => $date->startOfMonth()->startOfDay(),
            'year' => $date->startOfYear()->startOfDay(),
            default => throw new GameException('Diesen Rückblick gibt es nicht.'),
        };
    }

    /**
     * Rückblick holen bzw. einmalig erzeugen. Ohne Datum der zuletzt abgeschlossene Zeitraum;
     * Zeiträume, die noch laufen, gibt es nicht. Endete der Zeitraum vor dem Beitritt, gibt es keinen.
     */
    public function get(User $user, string $kind, ?CarbonImmutable $date = null): ?Review
    {
        $latest = self::latestStart($user, $kind);
        $start = $date ? self::startOf($date, $kind) : $latest;
        if ($start->gt($latest)) {
            $start = $latest;
        }
        $end = self::periodEnd($start, $kind);
        if ($user->gameDate($user->created_at ?? now())->gt($end)) {
            return null;
        }

        $existing = Review::where('user_id', $user->id)->where('kind', $kind)->whereDate('period_start', $start->toDateString())->first();
        if ($existing) {
            return $existing;
        }

        $data = match ($kind) {
            'week' => $this->week($user, $start, $end),
            'month' => $this->month($user, $start, $end),
            'year' => $this->year($user, $start, $end),
        };

        return Review::firstOrCreate(
            ['user_id' => $user->id, 'kind' => $kind, 'period_start' => $start->toDateString()],
            ['family_id' => $user->family_id, 'period_end' => $end->toDateString(), 'data' => $data],
        );
    }

    /** Darstellung für eine betrachtende Person; private Inhalte sieht nur die Person selbst. */
    public static function present(?Review $review, User $viewer): ?array
    {
        if ($review === null) {
            return null;
        }

        $data = $review->data;
        if ($viewer->id !== $review->user_id) {
            $data = self::redact($data);
        }

        return [
            'id' => $review->id,
            'kind' => $review->kind,
            'user_id' => $review->user_id,
            'period_start' => $review->period_start->toDateString(),
            'period_end' => $review->period_end->toDateString(),
            'seen' => $review->seen_at !== null,
            'shared' => $review->shared_at !== null,
            'can_share' => $viewer->id === $review->user_id && $review->kind !== 'week' && $review->shared_at === null,
            ...$data,
        ];
    }

    /** Private Titel entfernen und nur als Anzahl zeigen. */
    public static function redact(array $data): array
    {
        $hidden = 0;
        foreach (['highlights', 'goals'] as $key) {
            if (isset($data[$key])) {
                $hidden += collect($data[$key])->where('private', true)->count();
                $data[$key] = collect($data[$key])->reject(fn ($i) => $i['private'] ?? false)->values()->all();
            }
        }
        if (($data['longest_streak']['private'] ?? false) === true) {
            $data['longest_streak']['task'] = null;
            $hidden++;
        }
        $data['private_items'] = ($data['private_items'] ?? 0) + $hidden;

        return $data;
    }

    public function markSeen(User $user, Review $review): void
    {
        abort_unless($review->user_id === $user->id, 403);
        $review->seen_at ??= now();
        $review->save();
    }

    /** Monats- oder Jahreskarte in den Familien-Feed teilen – ohne private Inhalte, nur einmal. */
    public function share(User $user, Review $review): void
    {
        abort_unless($review->user_id === $user->id, 403);
        if ($review->kind === 'week') {
            throw new GameException('Teilen geht mit der Monats- und der Jahreskarte.');
        }
        if ($review->shared_at !== null) {
            throw new GameException('Diese Karte hast du schon geteilt.');
        }

        $d = $review->data;
        $parts = array_filter([
            "{$d['tasks']} Aufgaben",
            ($d['active_days'] ?? 0) > 0 ? "{$d['active_days']} aktive Tage" : null,
            count($d['pets'] ?? []) > 0 ? count($d['pets']).' neue Haustiere' : null,
            count($d['badges'] ?? []) > 0 ? count($d['badges']).' Abzeichen' : null,
        ]);
        $what = $review->kind === 'month' ? "Monatskarte {$d['label']}" : "Jahresrückblick {$d['label']}";
        $this->feed->post($user->family_id, $user, 'review_shared', $review->kind === 'month' ? '🗓️' : '🎆',
            "{$user->name} teilt die {$what}: ".implode(', ', $parts).'!', ['review_id' => $review->id]);
        $review->forceFill(['shared_at' => now()])->save();
    }

    // ---------- Inhalte ----------

    private function week(User $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $days = $this->scores->daily($user, $from, $to);
        $completions = ActivityScores::completions(collect([$user->id]), ActivityScores::utcStart($user, $from), ActivityScores::utcStart($user, $to->addDay()));
        $before = ActivityScores::completions(collect([$user->id]), ActivityScores::utcStart($user, $from->subWeek()), ActivityScores::utcStart($user, $from))->count();

        $tasks = $completions->count();
        $active = collect($days)->where('tasks', '>', 0)->count();
        $goals = GoalData::reached($user, $from, $to);
        $streak = $this->longestStreak($user);
        $best = $this->bestDay($days);

        return [
            'label' => 'Woche vom '.$from->format('d.m.').' bis '.$to->format('d.m.'),
            'tasks' => $tasks,
            'active_days' => $active,
            'xp' => (int) $completions->sum('xp'),
            'points' => round(collect($days)->sum('points')),
            'message' => self::message($tasks, $before, $active),
            'more_than_before' => max(0, $tasks - $before),
            'highlights' => array_slice($this->highlights($user, $from, $to, $completions, $days, $streak), 0, 3),
            'goals' => $goals,
            'goals_supported' => GoalData::available(),
            'longest_streak' => $streak,
            'best_day' => $best,
            'perfect_days' => $this->perfectDays($user, $from, $to),
        ];
    }

    private function month(User $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $days = $this->scores->daily($user, $from, $to);
        $completions = ActivityScores::completions(collect([$user->id]), ActivityScores::utcStart($user, $from), ActivityScores::utcStart($user, $to->addDay()));
        $max = max(1, (int) collect($days)->max('tasks'));
        $pets = $this->pets($user, $from, $to);
        $badges = $this->badges($user, $from, $to);
        $bosses = $this->questsWon($user, $from, $to);
        $active = collect($days)->where('tasks', '>', 0)->count();
        $goals = GoalData::reached($user, $from, $to);

        return [
            'label' => self::MONTHS[$from->month].' '.$from->year,
            'tasks' => $completions->count(),
            'active_days' => $active,
            'xp' => (int) $completions->sum('xp'),
            // Kalender-Heatmap: Stufe 0–4 relativ zum eigenen besten Tag (Vergleich nur mit sich selbst)
            'heatmap' => collect($days)->map(fn ($d, $date) => [
                'date' => $date,
                'tasks' => $d['tasks'],
                'level' => $d['tasks'] > 0 ? (int) max(1, ceil($d['tasks'] / $max * 4)) : 0,
                'inn' => $d['sleeping'],
            ])->values()->all(),
            'first_weekday' => $from->isoWeekday(),
            'pets' => $pets,
            'badges' => $badges,
            'achievements' => UserAchievement::where('user_id', $user->id)
                ->whereBetween('created_at', [ActivityScores::utcStart($user, $from), ActivityScores::utcStart($user, $to->addDay())])->count(),
            'quests_won' => count($bosses),
            'goals' => $goals,
            'goals_supported' => GoalData::available(),
            'pride' => self::pride([
                count($bosses) > 0 ? (count($bosses) === 1 ? "Du hast {$bosses[0]} geschafft." : count($bosses).' Quests gewonnen.') : null,
                count($goals) > 0 ? (count($goals) === 1 ? 'Du hast ein Ziel erreicht.' : count($goals).' Ziele erreicht.') : null,
                $active >= 20 ? "An {$active} Tagen warst du dabei." : null,
                count($pets) > 0 ? (count($pets) === 1 ? 'Ein neues Haustier ist eingezogen.' : count($pets).' neue Haustiere sind eingezogen.') : null,
                $completions->count() > 0 ? "{$completions->count()} Aufgaben geschafft." : null,
                $active > 0 ? "An {$active} Tagen warst du dabei." : null,
            ]),
        ];
    }

    private function year(User $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $completions = ActivityScores::completions(collect([$user->id]), ActivityScores::utcStart($user, $from), ActivityScores::utcStart($user, $to->addDay()));
        $byMonth = $completions->groupBy(fn ($c) => (int) $user->gameDate($c->created_at)->month);
        $months = collect(range(1, 12))->map(fn ($m) => ['month' => $m, 'label' => self::MONTHS[$m], 'tasks' => $byMonth->get($m, collect())->count()]);
        $best = $months->sortByDesc('tasks')->first();
        $activeDays = $completions->map(fn ($c) => $user->gameDate($c->created_at)->toDateString())->unique()->count();
        $pets = $this->pets($user, $from, $to);
        $badges = $this->badges($user, $from, $to);
        $quests = $this->questsWon($user, $from, $to);
        $goals = GoalData::reached($user, $from, $to);
        $bestStreak = app(AchievementService::class)->metric($user, 'best_streak');

        return [
            'label' => (string) $from->year,
            'tasks' => $completions->count(),
            'active_days' => $activeDays,
            'xp' => (int) $completions->sum('xp'),
            'months' => $months->values()->all(),
            'best_month' => $best && $best['tasks'] > 0 ? $best['label'] : null,
            'pets' => $pets,
            'badges' => $badges,
            'quests_won' => count($quests),
            'goals' => $goals,
            'goals_supported' => GoalData::available(),
            'best_streak' => (int) $bestStreak,
            'pride' => self::pride([
                count($quests) > 0 ? count($quests).' Quests gemeinsam gewonnen.' : null,
                count($goals) > 0 ? count($goals).' Ziele erreicht.' : null,
                $completions->count() > 0 ? "{$completions->count()} Aufgaben in einem Jahr!" : null,
                $activeDays > 0 ? "An {$activeDays} Tagen warst du dabei." : null,
            ]),
        ];
    }

    /** Nur Positives: mehr als letzte Woche, sonst das Geschaffte. */
    public static function message(int $tasks, int $before, int $activeDays): string
    {
        return match (true) {
            $tasks > $before && $before > 0 => ($tasks - $before === 1 ? 'Eine Aufgabe' : ($tasks - $before).' Aufgaben').' mehr als letzte Woche!',
            $tasks > 0 && $tasks === $before => 'Genauso fleißig wie letzte Woche!',
            $activeDays > 0 => $activeDays === 1 ? 'Du hast an einem Tag Aufgaben geschafft.' : "Du hast an {$activeDays} Tagen Aufgaben geschafft.",
            default => 'Neue Woche, neues Abenteuer! Schön, dass du da bist.',
        };
    }

    private static function pride(array $candidates): string
    {
        $first = collect($candidates)->filter()->first();

        return $first ? "Darauf kannst du stolz sein: {$first}" : 'Darauf kannst du stolz sein: Du bist Teil des Heldenhauses!';
    }

    /** @return list<array{icon: string, text: string, private: bool}> */
    private function highlights(User $user, CarbonImmutable $from, CarbonImmutable $to, Collection $completions, array $days, ?array $streak): array
    {
        $out = [];
        foreach ($this->questsWon($user, $from, $to) as $name) {
            $out[] = ['icon' => '🏆', 'text' => "{$name} geschafft!", 'private' => false];
        }
        foreach (GoalData::reached($user, $from, $to) as $goal) {
            $out[] = ['icon' => '🎯', 'text' => "Ziel erreicht: {$goal['title']}", 'private' => $goal['private']];
        }
        foreach ($this->badges($user, $from, $to) as $badge) {
            $out[] = ['icon' => $badge['icon'], 'text' => "Abzeichen „{$badge['name']}“", 'private' => false];
        }
        foreach ($this->pets($user, $from, $to) as $pet) {
            $out[] = ['icon' => $pet['icon'], 'text' => "Neues Haustier: {$pet['name']}", 'private' => false];
        }
        $perfect = $this->perfectDays($user, $from, $to);
        if ($perfect > 0) {
            $out[] = ['icon' => '✨', 'text' => $perfect === 1 ? 'Ein perfekter Tag mit allen Dailies' : "{$perfect} perfekte Tage mit allen Dailies", 'private' => false];
        }
        if ($streak && $streak['days'] >= 3) {
            $out[] = ['icon' => '🔥', 'text' => "{$streak['days']} Tage am Stück".($streak['private'] ? '' : ": {$streak['task']}"), 'private' => $streak['private']];
        }
        $top = $completions->groupBy('task_id')->sortByDesc(fn ($g) => $g->count())->first();
        if ($top && $top->count() >= 3) {
            $private = (bool) $top->first()->is_private;
            $out[] = ['icon' => '💪', 'text' => "{$top->count()}× „{$top->first()->title}“", 'private' => $private];
        }
        if ($completions->sum('xp') > 0) {
            $out[] = ['icon' => '⭐', 'text' => $completions->sum('xp').' XP gesammelt', 'private' => false];
        }

        return $out;
    }

    /** @return array{days: int, task: ?string, private: bool}|null */
    private function longestStreak(User $user): ?array
    {
        $a = TaskAssignment::with('task:id,title,is_private')->where('user_id', $user->id)->where('streak', '>', 0)->orderByDesc('streak')->first();

        return $a ? ['days' => (int) $a->streak, 'task' => $a->task?->title, 'private' => (bool) $a->task?->is_private] : null;
    }

    /** @return array{date: string, weekday: string, tasks: int}|null */
    private function bestDay(array $days): ?array
    {
        $best = collect($days)->filter(fn ($d) => $d['tasks'] > 0)->sortByDesc(fn ($d) => [$d['points'], $d['tasks']])->keys()->first();
        if ($best === null) {
            return null;
        }
        $date = CarbonImmutable::parse($best);

        return ['date' => $best, 'weekday' => self::WEEKDAYS[$date->isoWeekday()], 'tasks' => $days[$best]['tasks']];
    }

    private function perfectDays(User $user, CarbonImmutable $from, CarbonImmutable $to): int
    {
        return DayLog::where('user_id', $user->id)->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->where('dailies_due', '>', 0)->whereColumn('dailies_done', '>=', 'dailies_due')->count();
    }

    /** @return list<array{name: string, icon: string}> */
    private function pets(User $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return UserPet::with('egg:id,name,icon')->where('user_id', $user->id)
            ->whereBetween('created_at', [ActivityScores::utcStart($user, $from), ActivityScores::utcStart($user, $to->addDay())])
            ->get()->map(fn (UserPet $p) => ['name' => $p->name ?: ($p->egg?->name ?? 'Haustier'), 'icon' => $p->egg?->icon ?: '🐾'])->values()->all();
    }

    /** @return list<array{name: string, icon: string}> */
    private function badges(User $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return UserBadge::where('user_id', $user->id)
            ->whereBetween('created_at', [ActivityScores::utcStart($user, $from), ActivityScores::utcStart($user, $to->addDay())])
            ->get(['name', 'icon'])->map(fn ($b) => ['name' => $b->name, 'icon' => $b->icon ?: '🏅'])->values()->all();
    }

    /** Namen der gewonnenen Quests, bei denen die Person mitgespielt hat. @return list<string> */
    private function questsWon(User $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return Quest::where('family_id', $user->family_id)->where('status', 'completed')
            ->whereBetween('completed_at', [ActivityScores::utcStart($user, $from), ActivityScores::utcStart($user, $to->addDay())])
            ->where(fn ($q) => $q->where('user_id', $user->id)
                ->orWhere(fn ($f) => $f->where('scope', 'family')->whereIn('id', QuestContribution::where('user_id', $user->id)->select('quest_id'))))
            ->orderBy('completed_at')->pluck('name')->all();
    }
}
