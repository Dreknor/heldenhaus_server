<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ausgewertete Woche eines Wochenziels (Sonntagabend in der Zeitzone der Person). */
class GoalWeek extends Model
{
    protected $fillable = ['goal_id', 'week_start', 'done', 'target', 'reached'];

    protected function casts(): array
    {
        return [
            'week_start' => 'date',
            'done' => 'integer',
            'target' => 'integer',
            'reached' => 'boolean',
        ];
    }

    public function goal(): BelongsTo
    {
        return $this->belongsTo(Goal::class);
    }
}
