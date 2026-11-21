<?php

namespace App\Models;

use App\Casts\DateOnly;
use Illuminate\Database\Eloquent\Model;

/** Tageskennzahlen einer Familie (Konzept §14.10), je Mitglied zusammengefasst. */
class FamilyMetric extends Model
{
    protected $fillable = ['family_id', 'date', 'data'];

    protected function casts(): array
    {
        return [
            'date' => DateOnly::class,
            'data' => 'array',
        ];
    }
}
