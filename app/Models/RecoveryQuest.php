<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecoveryQuest extends Model
{
    protected $fillable = ['user_id', 'assignment_id', 'needed', 'done', 'streak_to_restore', 'expires_on', 'status'];

    protected function casts(): array
    {
        return ['expires_on' => 'date', 'needed' => 'integer', 'done' => 'integer', 'streak_to_restore' => 'integer'];
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(TaskAssignment::class);
    }
}
