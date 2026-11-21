<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Heldenjahr einer Person in einem Schuljahr (§14.7). */
class HeroYear extends Model
{
    protected $fillable = ['user_id', 'start_year', 'starts_on', 'ends_on', 'points', 'level', 'rewarded_level', 'points_today', 'points_on'];

    protected $attributes = ['points' => 0, 'level' => 0, 'rewarded_level' => 0, 'points_today' => 0];

    protected function casts(): array
    {
        return [
            'start_year' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'points' => 'integer',
            'level' => 'integer',
            'rewarded_level' => 'integer',
            'points_today' => 'integer',
            'points_on' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
