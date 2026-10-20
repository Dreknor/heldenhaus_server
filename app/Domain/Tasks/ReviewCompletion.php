<?php

namespace App\Domain\Tasks;

use App\Domain\Achievements\AchievementMetrics;
use App\Domain\Achievements\AchievementService;
use App\Domain\Gameplay\GameException;
use App\Domain\Gameplay\ScoreTask;
use App\Domain\Social\FeedService;
use App\Enums\CompletionStatus;
use App\Models\TaskAssignment;
use App\Models\TaskCompletion;
use App\Models\User;
use App\Models\UserStats;
use Illuminate\Support\Facades\DB;

/** Freigabe-Workflow für Familienaufgaben: bestätigen, ablehnen, automatisch bestätigen. */
class ReviewCompletion
{
    public function approve(TaskCompletion $completion, ?User $reviewer, CompletionStatus $status = CompletionStatus::Approved): TaskCompletion
    {
        $completion = DB::transaction(function () use ($completion, $reviewer, $status) {
            $completion = TaskCompletion::lockForUpdate()->findOrFail($completion->id);
            $this->ensurePending($completion);

            $completion->forceFill([
                'status' => $status,
                'reviewed_by' => $reviewer?->id,
                'reviewed_at' => now(),
            ])->save();

            if ($completion->stars > 0) {
                UserStats::whereKey($completion->user_id)->increment('stars', $completion->stars);
            }

            $completion->loadMissing('task', 'user');
            app(FeedService::class)->post($completion->task->family_id, $completion->user, 'task_done', '✅', "{$completion->user->name} hat „{$completion->task->title}“ erledigt");

            return $completion;
        });

        // Familienaufgaben mit Freigabe zählen erst ab der Bestätigung
        app(AchievementService::class)->evaluate($completion->user, AchievementMetrics::TASKS);

        return $completion;
    }

    public function reject(TaskCompletion $completion, User $reviewer, ?string $comment = null): TaskCompletion
    {
        return DB::transaction(function () use ($completion, $reviewer, $comment) {
            $completion = TaskCompletion::lockForUpdate()->findOrFail($completion->id);
            $this->ensurePending($completion);

            $assignment = TaskAssignment::lockForUpdate()->findOrFail($completion->assignment_id);
            $stats = UserStats::lockForUpdate()->findOrFail($completion->user_id);

            ScoreTask::reverse($assignment, $stats, $completion, CompletionStatus::Rejected, $comment, $reviewer->id);

            return $completion;
        });
    }

    /** Offene Freigaben nach Ablauf der Frist automatisch bestätigen. */
    public function autoApproveExpired(): int
    {
        $count = 0;

        TaskCompletion::with('task.family')
            ->where('status', CompletionStatus::Pending)
            ->where('created_at', '<=', now()->subHour())
            ->chunkById(100, function ($completions) use (&$count) {
                foreach ($completions as $completion) {
                    $hours = (int) ($completion->task->family?->setting('auto_approve_hours') ?? config('game.auto_approve_hours'));

                    if ($hours > 0 && $completion->created_at->lte(now()->subHours($hours))) {
                        $this->approve($completion, null, CompletionStatus::Auto);
                        $count++;
                    }
                }
            });

        return $count;
    }

    private function ensurePending(TaskCompletion $completion): void
    {
        if ($completion->status !== CompletionStatus::Pending) {
            throw new GameException('Diese Freigabe wurde schon bearbeitet.');
        }
    }
}
