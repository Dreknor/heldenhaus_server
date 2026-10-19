<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SkillCast extends Model
{
    protected $table = 'skill_casts';

    protected $fillable = ['user_id', 'skill_key', 'target_user_id', 'mana_cost'];

    protected function casts(): array
    {
        return ['mana_cost' => 'integer'];
    }
}
