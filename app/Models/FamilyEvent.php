<?php

namespace App\Models;

use App\Domain\Events\EventCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Eine Event-Instanz einer Familie (§12.6): geplant → aktiv → endet bald → beendet. */
class FamilyEvent extends Model
{
    public const RUNNING = ['active', 'ending'];

    protected $fillable = ['family_id', 'subject_user_id', 'event_key', 'status', 'starts_at', 'ends_at', 'queued_until', 'trigger_source', 'seed', 'progress', 'quest_id'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'queued_until' => 'datetime', 'progress' => 'array'];
    }

    public function scopeRunning(Builder $q): Builder
    {
        return $q->whereIn('status', self::RUNNING);
    }

    public function definition(): array
    {
        return EventCatalog::get($this->event_key) ?? [];
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }

    public function participations(): HasMany
    {
        return $this->hasMany(EventParticipation::class);
    }
}
