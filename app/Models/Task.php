<?php

namespace App\Models;

use App\Enums\ApprovalMode;
use App\Enums\TaskType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Task extends Model
{
    protected $fillable = [
        'family_id', 'owner_id', 'created_by', 'scope', 'type', 'title', 'notes', 'category', 'difficulty',
        'up', 'down', 'repeat', 'start_date', 'due_date', 'checklist', 'is_private', 'approval_mode',
        'assignment_mode', 'star_reward', 'gold_cost', 'ha_event_key', 'sort', 'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => TaskType::class,
            'approval_mode' => ApprovalMode::class,
            'difficulty' => 'float',
            'up' => 'boolean',
            'down' => 'boolean',
            'repeat' => 'array',
            'checklist' => 'array',
            'start_date' => 'date',
            'due_date' => 'date',
            'is_private' => 'boolean',
            'star_reward' => 'integer',
            'gold_cost' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(TaskAssignment::class);
    }

    public function completions(): HasMany
    {
        return $this->hasMany(TaskCompletion::class);
    }

    public function isFamilyTask(): bool
    {
        return $this->scope === 'family';
    }

    public function isPool(): bool
    {
        return $this->isFamilyTask() && $this->assignment_mode === 'pool';
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    /** Ist eine tägliche Aufgabe an diesem (Spiel-)Tag fällig? */
    public function isDueOn(\DateTimeInterface $date): bool
    {
        if ($this->type !== TaskType::Daily) {
            return false;
        }

        $day = CarbonImmutable::instance($date)->startOfDay();
        $start = $this->start_date ? CarbonImmutable::parse($this->start_date->format('Y-m-d')) : null;

        if ($start && $day->lt($start)) {
            return false;
        }

        $repeat = $this->repeat ?? ['type' => 'daily', 'every' => 1];

        return match ($repeat['type'] ?? 'daily') {
            'weekly' => in_array($day->dayOfWeekIso, array_map('intval', $repeat['days'] ?? []), true),
            'monthly' => $day->day === (int) ($repeat['day_of_month'] ?? 1),
            default => $this->isDueEveryXDays($day, $start, max(1, (int) ($repeat['every'] ?? 1))),
        };
    }

    private function isDueEveryXDays(CarbonImmutable $day, ?CarbonImmutable $start, int $every): bool
    {
        if ($every === 1 || $start === null) {
            return true;
        }

        return ((int) $start->diffInDays($day)) % $every === 0;
    }
}
