<?php

namespace App\Models;

use App\Casts\DateOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Persönlicher Rückblick (Konzept §14.5): Woche, Monat oder Jahr. */
class Review extends Model
{
    protected $fillable = ['user_id', 'family_id', 'kind', 'period_start', 'period_end', 'data', 'seen_at', 'shared_at'];

    protected function casts(): array
    {
        return [
            'period_start' => DateOnly::class,
            'period_end' => DateOnly::class,
            'data' => 'array',
            'seen_at' => 'datetime',
            'shared_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
