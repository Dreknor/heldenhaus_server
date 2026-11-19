<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Abstimmung der Familie (§14.4), z. B. über die nächste Familien-Quest. Stimmen: {user_id: option}. */
class Vote extends Model
{
    protected $fillable = [
        'family_id', 'occasion', 'options', 'ballots', 'status', 'winner', 'opened_by', 'decided_by',
        'quest_id', 'family_council_id', 'closes_at', 'closed_at',
    ];

    protected function casts(): array
    {
        return ['options' => 'array', 'ballots' => 'array', 'closes_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    public function quest(): BelongsTo
    {
        return $this->belongsTo(Quest::class);
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    /** @return list<string> */
    public function optionKeys(): array
    {
        return array_column($this->options ?? [], 'key');
    }
}
