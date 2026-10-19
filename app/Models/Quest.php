<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Quest extends Model
{
    protected $fillable = [
        'family_id', 'user_id', 'scope', 'type', 'quest_key', 'name', 'icon', 'goal', 'progress', 'data',
        'rewards', 'reward_text', 'status', 'ends_at', 'completed_at', 'started_by',
    ];

    protected function casts(): array
    {
        return [
            'goal' => 'float',
            'progress' => 'float',
            'data' => 'array',
            'rewards' => 'array',
            'ends_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function contributions(): HasMany
    {
        return $this->hasMany(QuestContribution::class);
    }

    public function isBoss(): bool
    {
        return $this->type === 'boss';
    }

    public function remaining(): float
    {
        return max(0, $this->goal - $this->progress);
    }
}
