<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RewardRedemption extends Model
{
    protected $fillable = ['reward_item_id', 'user_id', 'stars', 'status', 'reviewed_by', 'reviewed_at', 'review_comment'];

    protected function casts(): array
    {
        return ['stars' => 'integer', 'reviewed_at' => 'datetime'];
    }

    public function rewardItem(): BelongsTo
    {
        return $this->belongsTo(RewardItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
