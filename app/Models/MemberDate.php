<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Freiwillige Familiendaten (§12.6), nur Tag und Monat. */
class MemberDate extends Model
{
    protected $fillable = ['user_id', 'type', 'month', 'day'];

    protected function casts(): array
    {
        return ['month' => 'integer', 'day' => 'integer'];
    }
}
