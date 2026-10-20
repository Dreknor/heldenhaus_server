<?php

namespace App\Domain\Social;

use App\Domain\Gameplay\GameException;
use App\Domain\Gameplay\Progression;
use App\Domain\Tasks\TaskService;
use App\Enums\CompletionStatus;
use App\Enums\FamilyRole;
use App\Models\Challenge;
use App\Models\ChallengeParticipant;
use App\Models\FamilyAlliance;
use App\Models\Task;
use App\Models\TaskCompletion;
use App\Models\User;
use App\Models\UserStats;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Challenges (Konzept §3.6): Eltern legen ein Aufgabenpaket mit Zeitraum an („7 Tage lesen“).
 * Wer mitmacht, bekommt die Aufgaben als eigene persönliche Aufgaben; gezählt werden Abschlüsse im Zeitraum.
 * Mit „alliance“ können auch verbündete Familien mitmachen. Eine Rangliste gibt es nur, wenn die Eltern sie wollen.
 */
class ChallengeService
{
    public function __construct(private readonly TaskService $tasks, private readonly FeedService $feed) {}

    public function create(User $parent, array $data): Challenge
    {
        if (! $parent->isParent()) {
            throw new GameException('Challenges legen Eltern an.');
        }

        $challenge = Challenge::create([...$data, 'family_id' => $parent->family_id, 'created_by' => $parent->id]);

        $this->feed->post($parent->family_id, $parent, 'challenge', $challenge->icon, "Neue Challenge: „{$challenge->title}“ – wer macht mit?");

        return $challenge;
    }

    public function join(User $user, Challenge $challenge): ChallengeParticipant
    {
        if (! $challenge->visibleTo($user->family_id)) {
            throw new GameException('Diese Challenge gibt es für dich nicht.');
        }
        if ($user->family_role === FamilyRole::Guest) {
            throw new GameException('Gäste schauen bei Challenges nur zu.');
        }
        if ($challenge->closed_at !== null || $challenge->ends_on->lt($user->gameDate())) {
            throw new GameException('Diese Challenge ist schon vorbei.');
        }

        return DB::transaction(function () use ($user, $challenge) {
            $participant = ChallengeParticipant::firstOrNew(['challenge_id' => $challenge->id, 'user_id' => $user->id]);
            if ($participant->exists && $participant->left_at === null) {
                throw new GameException('Du machst schon mit.');
            }
            $participant->left_at = null;
            $participant->save();

            $start = $challenge->starts_on->max($user->gameDate())->toDateString();
            foreach ($challenge->tasks as $definition) {
                $task = $this->tasks->create($user, array_filter([
                    'type' => $definition['type'],
                    'title' => $definition['title'],
                    'category' => $definition['category'] ?? 'school',
                    'difficulty' => 1,
                    'notes' => "Challenge „{$challenge->title}“ bis {$challenge->ends_on->format('d.m.')}",
                    'up' => $definition['type'] === 'habit' ? true : null,
                    'repeat' => $definition['type'] === 'daily' ? ['type' => 'daily', 'every' => 1] : null,
                    'start_date' => $definition['type'] === 'daily' ? $start : null,
                    'due_date' => $definition['type'] === 'todo' ? $challenge->ends_on->toDateString() : null,
                ], fn ($v) => $v !== null));
                $task->forceFill(['challenge_id' => $challenge->id])->save();
            }

            return $participant;
        });
    }

    public function leave(User $user, Challenge $challenge): void
    {
        ChallengeParticipant::where(['challenge_id' => $challenge->id, 'user_id' => $user->id])->update(['left_at' => now()]);
        Task::where('challenge_id', $challenge->id)->where('owner_id', $user->id)->whereNull('archived_at')->update(['archived_at' => now()]);
    }

    public function destroy(User $parent, Challenge $challenge): void
    {
        if (! $parent->isParent() || $challenge->family_id !== $parent->family_id) {
            throw new GameException('Nur Eltern der Familie, die die Challenge angelegt hat, können sie beenden.');
        }

        $this->close($challenge);
    }

    /** Nach einem Abschluss: Ziel erreicht? Dann einmalig Gold und ein Feed-Eintrag. */
    public function progress(TaskCompletion $completion): void
    {
        $task = $completion->task;
        if ($task?->challenge_id === null || $completion->direction !== 'up') {
            return;
        }

        $challenge = Challenge::find($task->challenge_id);
        $participant = ChallengeParticipant::where(['challenge_id' => $task->challenge_id, 'user_id' => $completion->user_id])->whereNull('left_at')->first();
        if ($challenge === null || $participant === null || $participant->completed_at !== null) {
            return;
        }

        if (($this->scores($challenge, [$completion->user_id])[$completion->user_id] ?? 0) < $challenge->target) {
            return;
        }

        DB::transaction(function () use ($challenge, $participant) {
            $participant->update(['completed_at' => now()]);
            if ($challenge->reward_gold > 0) {
                $stats = UserStats::lockForUpdate()->findOrFail($participant->user_id);
                Progression::changeGold($stats, $challenge->reward_gold);
                $stats->save();
            }
        });

        $user = $participant->user;
        $this->feed->post($user->family_id, $user, 'challenge_done', $challenge->icon, "{$user->name} hat die Challenge „{$challenge->title}“ geschafft!");
    }

    /** Abgelaufene Challenges schließen und ihre Aufgaben archivieren. */
    public function closeExpired(): int
    {
        $count = 0;
        Challenge::whereNull('closed_at')->where('ends_on', '<', now()->subDay()->toDateString())->get()
            ->each(function (Challenge $c) use (&$count) {
                $this->close($c);
                $count++;
            });

        return $count;
    }

    /** @return array<int, int> Abschlüsse im Zeitraum je Person */
    public function scores(Challenge $challenge, array|Collection $userIds): array
    {
        return TaskCompletion::query()
            ->whereIn('user_id', $userIds)
            ->where('direction', 'up')
            ->whereNotIn('status', [CompletionStatus::Undone->value, CompletionStatus::Rejected->value])
            ->whereHas('task', fn ($q) => $q->where('challenge_id', $challenge->id))
            ->where('created_at', '>=', $challenge->starts_on->copy()->subDay()->startOfDay())
            ->where('created_at', '<=', $challenge->ends_on->copy()->addDay()->endOfDay())
            ->selectRaw('user_id, count(*) as n')->groupBy('user_id')
            ->pluck('n', 'user_id')->map(fn ($n) => (int) $n)->all();
    }

    /** Challenges, die eine Familie sieht: eigene und die der Verbündeten (mit scope „alliance“). */
    public function visible(User $viewer): Collection
    {
        $allied = FamilyAlliance::alliedFamilyIds($viewer->family_id);

        return Challenge::with('family:id,name')
            ->where(fn ($q) => $q->where('family_id', $viewer->family_id)
                ->orWhere(fn ($q) => $q->whereIn('family_id', $allied)->where('scope', 'alliance')))
            ->where(fn ($q) => $q->whereNull('closed_at')->orWhere('closed_at', '>', now()->subDays(14)))
            ->latest('starts_on')
            ->get();
    }

    public function present(Challenge $challenge, User $viewer): array
    {
        $participants = $challenge->participants()->whereNull('left_at')->with('user:id,name,family_id,avatar_color', 'user.family:id,name')->get();
        $scores = $this->scores($challenge, $participants->pluck('user_id'));
        $mine = $participants->firstWhere('user_id', $viewer->id);

        return [
            'id' => $challenge->id,
            'title' => $challenge->title,
            'description' => $challenge->description,
            'icon' => $challenge->icon,
            'tasks' => $challenge->tasks,
            'target' => $challenge->target,
            'reward_gold' => $challenge->reward_gold,
            'starts_on' => $challenge->starts_on->toDateString(),
            'ends_on' => $challenge->ends_on->toDateString(),
            'scope' => $challenge->scope,
            'family' => $challenge->family?->name,
            'own' => $challenge->family_id === $viewer->family_id,
            'running' => $challenge->isRunning($viewer->gameDate()),
            'closed' => $challenge->closed_at !== null,
            'leaderboard_enabled' => $challenge->leaderboard,
            'participants' => $participants->count(),
            'joined' => $mine !== null,
            'my_score' => $mine ? ($scores[$viewer->id] ?? 0) : null,
            'completed' => $mine?->completed_at !== null,
            'leaderboard' => $challenge->leaderboard
                ? $participants->map(fn (ChallengeParticipant $p) => [
                    'name' => $p->user->name,
                    'family' => $p->user->family?->name,
                    'avatar_color' => $p->user->avatar_color,
                    'score' => $scores[$p->user_id] ?? 0,
                    'completed' => $p->completed_at !== null,
                    'me' => $p->user_id === $viewer->id,
                ])->sortByDesc('score')->values()
                : null,
        ];
    }

    private function close(Challenge $challenge): void
    {
        $challenge->update(['closed_at' => now()]);
        Task::where('challenge_id', $challenge->id)->whereNull('archived_at')->update(['archived_at' => now()]);
    }
}
