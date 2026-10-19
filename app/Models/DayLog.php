<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DayLog extends Model
{
    protected $fillable = ['user_id', 'date', 'dailies_due', 'dailies_done', 'damage', 'knocked_out', 'sleeping'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'damage' => 'float',
            'knocked_out' => 'boolean',
            'sleeping' => 'boolean',
        ];
    }
}
