<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StepTemplate extends Model
{
    protected $fillable = ['family_id', 'title_key', 'steps', 'source'];

    protected function casts(): array
    {
        return ['steps' => 'array'];
    }
}
