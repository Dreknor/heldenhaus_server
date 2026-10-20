<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Ein Mini-Dungeon (Konzept §2.7): pro Woche einer für die Familie, pro Tag ein Solo-Gewölbe je Person. */
class Dungeon extends Model
{
    protected $fillable = [
        'family_id', 'user_id', 'kind', 'theme', 'seed', 'mode', 'status', 'starts_on', 'ends_at', 'quest_id',
        'state', 'counter', 'boss_unlocked', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'state' => 'array',
            'counter' => 'integer',
            'boss_unlocked' => 'boolean',
            'starts_on' => 'date',
            'ends_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function positions(): HasMany
    {
        return $this->hasMany(DungeonPosition::class);
    }

    public function actions(): HasMany
    {
        return $this->hasMany(DungeonAction::class);
    }

    public function quest(): BelongsTo
    {
        return $this->belongsTo(Quest::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
