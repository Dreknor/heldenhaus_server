<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HolidayPeriod extends Model
{
    protected $fillable = ['country', 'subdivision', 'type', 'name', 'starts_on', 'ends_on', 'source', 'family_id', 'fetched_at'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'fetched_at' => 'datetime'];
    }
}
