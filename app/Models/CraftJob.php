<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CraftJob extends Model
{
    protected $fillable = [
        'user_id', 'recipe_key', 'item_id', 'quantity', 'quality', 'started_at', 'finishes_at', 'status', 'notified', 'collected_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'notified' => 'boolean',
            'started_at' => 'datetime',
            'finishes_at' => 'datetime',
            'collected_at' => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isDone(): bool
    {
        return $this->status !== 'collected' && $this->finishes_at->lte(now());
    }
}
