<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FocusSession extends Model
{
    protected $fillable = ['user_id', 'task_id', 'planned_minutes', 'started_at', 'ends_at', 'ended_at', 'result', 'group_uuid', 'invited_by', 'bonus_gold'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'ends_at' => 'datetime', 'ended_at' => 'datetime', 'planned_minutes' => 'integer', 'bonus_gold' => 'float'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function isRunning(): bool
    {
        return $this->ended_at === null;
    }
}
