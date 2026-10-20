<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Handwerksstufe einer Person (Konzept §2.5). */
class UserCrafting extends Model
{
    protected $table = 'user_crafting';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $fillable = ['user_id', 'level', 'xp'];

    protected $attributes = ['level' => 1, 'xp' => 0];

    protected function casts(): array
    {
        return ['level' => 'integer', 'xp' => 'integer'];
    }
}
