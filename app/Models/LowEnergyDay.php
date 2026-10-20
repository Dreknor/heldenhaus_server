<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LowEnergyDay extends Model
{
    protected $fillable = ['user_id', 'date', 'set_by'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }
}
