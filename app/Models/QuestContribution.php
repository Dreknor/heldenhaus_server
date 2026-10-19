<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuestContribution extends Model
{
    protected $fillable = ['quest_id', 'user_id', 'task_completion_id', 'amount'];

    protected function casts(): array
    {
        return ['amount' => 'float'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
