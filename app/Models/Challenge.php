<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Challenge extends Model
{
    protected $fillable = ['family_id', 'created_by', 'title', 'description', 'icon', 'tasks', 'target', 'reward_gold', 'starts_on', 'ends_on', 'scope', 'leaderboard', 'closed_at'];

    protected function casts(): array
    {
        return [
            'tasks' => 'array',
            'target' => 'integer',
            'reward_gold' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'leaderboard' => 'boolean',
            'closed_at' => 'datetime',
        ];
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(ChallengeParticipant::class);
    }

    public function isRunning(\DateTimeInterface $today): bool
    {
        $date = $today->format('Y-m-d');

        return $this->closed_at === null && $this->starts_on->toDateString() <= $date && $this->ends_on->toDateString() >= $date;
    }

    /** Darf eine Familie die Challenge sehen und mitmachen? */
    public function visibleTo(?int $familyId): bool
    {
        if ($familyId === null) {
            return false;
        }

        return $this->family_id === $familyId
            || ($this->scope === 'alliance' && in_array($familyId, FamilyAlliance::alliedFamilyIds($this->family_id), true));
    }
}
