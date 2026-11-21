<?php

namespace App\Models;

use App\Casts\DateOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Tagespunkte einer Person (Konzept §14.6): Boss-Schaden-Formel, Sammel-Einheiten, Aufgaben, Gasthaus-Tag. */
class ActivityScore extends Model
{
    protected $fillable = ['user_id', 'family_id', 'date', 'points', 'units', 'tasks', 'sleeping'];

    protected function casts(): array
    {
        return [
            'date' => DateOnly::class,
            'points' => 'float',
            'units' => 'integer',
            'tasks' => 'integer',
            'sleeping' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
