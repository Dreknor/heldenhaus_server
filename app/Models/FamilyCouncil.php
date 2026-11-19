<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Wöchentlicher Familienrat (§14.4): Rückblick, Danke-Runde, Entscheidungen, Abstimmung, Wünsche. */
class FamilyCouncil extends Model
{
    protected $fillable = ['family_id', 'held_on', 'status', 'step', 'recap', 'thanks', 'decisions', 'wishes', 'started_by', 'finished_at'];

    protected function casts(): array
    {
        return [
            'held_on' => 'date',
            'step' => 'integer',
            'recap' => 'array',
            'thanks' => 'array',
            'decisions' => 'array',
            'wishes' => 'array',
            'finished_at' => 'datetime',
        ];
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    public function isRunning(): bool
    {
        return $this->status === 'running';
    }
}
