<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Vorschlag eines Kindes (§14.4): Belohnung für den Sterne-Shop oder Aufgabe für Börse bzw. Eltern. */
class Proposal extends Model
{
    protected $fillable = [
        'family_id', 'user_id', 'kind', 'target', 'for_user_id', 'template_key', 'title', 'icon', 'stars',
        'status', 'reason', 'decided_by', 'decided_at', 'reward_item_id', 'task_id', 'family_council_id',
    ];

    protected function casts(): array
    {
        return ['stars' => 'integer', 'decided_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function forUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'for_user_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}
