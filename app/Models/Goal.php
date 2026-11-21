<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Ziel einer Person (§14.1): Wochenziel, Meilenstein-Ziel oder Zähl-Ziel. */
class Goal extends Model
{
    public const TYPES = ['weekly' => 'Wochenziel', 'milestone' => 'Meilensteine', 'count' => 'Zähl-Ziel'];

    protected $fillable = [
        'family_id', 'user_id', 'created_by', 'type', 'source', 'template_key', 'title', 'why', 'icon', 'category', 'target', 'unit',
        'manual_progress', 'ends_on', 'status', 'is_private', 'reward_stars', 'reward_wish', 'weeks_reached', 'reached_at',
    ];

    protected function casts(): array
    {
        return [
            'target' => 'integer',
            'manual_progress' => 'integer',
            'ends_on' => 'date',
            'is_private' => 'boolean',
            'reward_stars' => 'integer',
            'weeks_reached' => 'integer',
            'reached_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function milestones(): HasMany
    {
        return $this->hasMany(GoalMilestone::class)->orderBy('position');
    }

    public function tasks(): BelongsToMany
    {
        return $this->belongsToMany(Task::class, 'goal_links');
    }

    public function supporters(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'goal_supporters')->withTimestamps();
    }

    public function weeks(): HasMany
    {
        return $this->hasMany(GoalWeek::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
