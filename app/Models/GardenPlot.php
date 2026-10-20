<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GardenPlot extends Model
{
    protected $fillable = ['family_id', 'slot', 'plant_key', 'sown_by', 'sown_at', 'watered_on', 'waterings', 'ready_at'];

    protected function casts(): array
    {
        return ['slot' => 'integer', 'waterings' => 'integer', 'sown_at' => 'datetime', 'ready_at' => 'datetime', 'watered_on' => 'date'];
    }
}
