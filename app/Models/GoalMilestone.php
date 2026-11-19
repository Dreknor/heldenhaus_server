<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoalMilestone extends Model
{
    protected $fillable = ['goal_id', 'position', 'title', 'requested_at', 'done_at', 'confirmed_by'];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'requested_at' => 'datetime',
            'done_at' => 'datetime',
        ];
    }

    public function goal(): BelongsTo
    {
        return $this->belongsTo(Goal::class);
    }
}
