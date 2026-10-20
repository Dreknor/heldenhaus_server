<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserAchievement extends Model
{
    protected $fillable = ['user_id', 'achievement_key', 'tier', 'threshold', 'reward_gold', 'seen_at'];

    protected function casts(): array
    {
        return [
            'tier' => 'integer',
            'threshold' => 'integer',
            'reward_gold' => 'integer',
            'seen_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
