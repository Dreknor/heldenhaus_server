<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserStats extends Model
{
    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $fillable = [
        'user_id', 'hp', 'mp', 'xp', 'gold', 'stars', 'level', 'class_key', 'class_changed_at',
        'str', 'int', 'con', 'per', 'unspent_points', 'streak_shields', 'sleeping', 'last_cron_on',
    ];

    protected function casts(): array
    {
        return [
            'hp' => 'float',
            'mp' => 'float',
            'gold' => 'float',
            'xp' => 'integer',
            'stars' => 'integer',
            'level' => 'integer',
            'str' => 'integer',
            'int' => 'integer',
            'con' => 'integer',
            'per' => 'integer',
            'unspent_points' => 'integer',
            'streak_shields' => 'integer',
            'sleeping' => 'boolean',
            'last_cron_on' => 'date',
            'class_changed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
