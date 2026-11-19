<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Freigeschaltetes Chronik-Kapitel einer Person mit Gelesen-Status (§14.8). */
class ChronicleUnlock extends Model
{
    protected $fillable = ['user_id', 'chapter_key', 'unlocked_at', 'read_at', 'opened_together_at'];

    protected function casts(): array
    {
        return [
            'unlocked_at' => 'datetime',
            'read_at' => 'datetime',
            'opened_together_at' => 'datetime',
        ];
    }
}
