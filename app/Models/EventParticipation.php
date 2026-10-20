<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventParticipation extends Model
{
    protected $fillable = ['family_event_id', 'user_id', 'points', 'collected', 'rewards_claimed_at'];

    protected function casts(): array
    {
        return ['points' => 'float', 'collected' => 'array', 'rewards_claimed_at' => 'datetime'];
    }

    public function familyEvent(): BelongsTo
    {
        return $this->belongsTo(FamilyEvent::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
