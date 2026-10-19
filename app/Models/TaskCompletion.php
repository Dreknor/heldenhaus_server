<?php

namespace App\Models;

use App\Enums\CompletionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskCompletion extends Model
{
    protected $fillable = [
        'task_id', 'assignment_id', 'user_id', 'direction', 'value_before', 'delta', 'xp', 'gold', 'hp', 'stars', 'drop_item_id', 'boss_damage', 'effects',
        'streak_before', 'status', 'source', 'proof_path', 'reviewed_by', 'reviewed_at', 'review_comment', 'client_uuid',
    ];

    protected function casts(): array
    {
        return [
            'status' => CompletionStatus::class,
            'value_before' => 'float',
            'delta' => 'float',
            'xp' => 'integer',
            'gold' => 'float',
            'hp' => 'float',
            'stars' => 'integer',
            'streak_before' => 'integer',
            'reviewed_at' => 'datetime',
            'boss_damage' => 'float',
            'effects' => 'array',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(TaskAssignment::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function dropItem(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'drop_item_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
