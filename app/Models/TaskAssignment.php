<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Zustand einer Aufgabe für genau eine Person (Wert, Streak, erledigt). */
class TaskAssignment extends Model
{
    protected $fillable = [
        'task_id', 'user_id', 'value', 'streak', 'streak_best', 'streak_paused_at', 'completed', 'completed_at', 'counter_up', 'counter_down', 'checklist_done',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'float',
            'streak' => 'integer',
            'completed' => 'boolean',
            'completed_at' => 'datetime',
            'counter_up' => 'integer',
            'counter_down' => 'integer',
            'checklist_done' => 'array',
            'streak_paused_at' => 'datetime',
            'streak_best' => 'integer',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function completions(): HasMany
    {
        return $this->hasMany(TaskCompletion::class, 'assignment_id');
    }
}
