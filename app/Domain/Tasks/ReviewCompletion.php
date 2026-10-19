<?php

namespace App\Domain\Tasks;

use App\Domain\Gameplay\GameException;
use App\Domain\Gameplay\ScoreTask;
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
        return DB::transaction(function () use ($completion, $reviewer, $status) {
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

            return $completion;
        });
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
