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
        'family_id', 'challenge_id', 'owner_id', 'created_by', 'scope', 'type', 'status', 'is_core', 'title', 'notes', 'category', 'difficulty',
        'up', 'down', 'repeat', 'start_date', 'due_date', 'checklist', 'is_private', 'approval_mode',
        'assignment_mode', 'rotation', 'star_reward', 'team_bonus_gold', 'gold_cost', 'ha_event_key', 'sort', 'archived_at',
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
            'rotation' => 'array',
            'team_bonus_gold' => 'integer',
            'checklist' => 'array',
            'start_date' => 'date',
            'due_date' => 'date',
            'is_private' => 'boolean',
            'is_core' => 'boolean',
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

        // Reine Kalenderdaten vergleichen – der Spieltag liegt in der Zeitzone der Person, start_date ist zeitzonenlos.
        $day = CarbonImmutable::parse($date->format('Y-m-d'));
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

    /** Ist die Aufgabe an diesem Tag für genau diese Person fällig (Ämterplan beachtet)? */
    public function isDueFor(\DateTimeInterface $date, int $userId): bool
    {
        return $this->isDueOn($date) && $this->isOnDuty($userId, $date);
    }

    /**
     * Ämterplan: Wer dran ist, ergibt sich rein aus dem Datum – (Perioden seit Start) mod Anzahl.
     * Ohne Rotation ist jede zugewiesene Person dran.
     */
    public function isOnDuty(int $userId, \DateTimeInterface $date): bool
    {
        return $this->dutyUserId($date) === null || $this->dutyUserId($date) === $userId;
    }

    public function dutyUserId(\DateTimeInterface $date): ?int
    {
        $members = array_values(array_map('intval', $this->rotation['members'] ?? []));
        if ($this->assignment_mode !== 'rotation' || $members === []) {
            return null;
        }

        $day = CarbonImmutable::parse($date->format('Y-m-d'));
        $start = CarbonImmutable::parse(($this->start_date ?? $this->created_at)->format('Y-m-d'));
        $periods = ($this->rotation['interval'] ?? 'daily') === 'weekly'
            ? intdiv((int) $start->startOfWeek()->diffInDays($day->startOfWeek(), false), 7)
            : (int) $start->diffInDays($day, false);

        $count = count($members);

        return $members[(($periods % $count) + $count) % $count];
    }

    private function isDueEveryXDays(CarbonImmutable $day, ?CarbonImmutable $start, int $every): bool
    {
        if ($every === 1 || $start === null) {
            return true;
        }

        return ((int) $start->diffInDays($day)) % $every === 0;
    }
}
