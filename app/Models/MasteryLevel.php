<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Meisterschaft einer Person in einer Aufgabenkategorie (§14.7), Stufe 0–5. */
class MasteryLevel extends Model
{
    protected $fillable = ['user_id', 'category', 'count', 'level'];

    protected $attributes = ['count' => 0, 'level' => 0];

    protected function casts(): array
    {
        return ['count' => 'integer', 'level' => 'integer'];
    }
}
