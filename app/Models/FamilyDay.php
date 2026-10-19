<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FamilyDay extends Model
{
    protected $table = 'family_days';

    protected $fillable = ['family_id', 'date', 'all_done', 'bonus_gold'];

    protected function casts(): array
    {
        return ['date' => 'date', 'all_done' => 'boolean'];
    }
}
