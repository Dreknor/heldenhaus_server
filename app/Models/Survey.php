<?php

namespace App\Models;

use App\Casts\DateOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ausgangslage-Fragen (Eltern) und Stimmungs-Check (alle) – Konzept §14.10. answers = null heißt übersprungen. */
class Survey extends Model
{
    protected $fillable = ['user_id', 'family_id', 'kind', 'date', 'answers'];

    protected function casts(): array
    {
        return [
            'date' => DateOnly::class,
            'answers' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
