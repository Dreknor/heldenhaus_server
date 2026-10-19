<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ActiveBuff extends Model
{
    protected $table = 'active_buffs';

    protected $fillable = ['family_id', 'user_id', 'source_user_id', 'key', 'value', 'armed', 'expires_at'];

    protected function casts(): array
    {
        return ['value' => 'float', 'armed' => 'boolean', 'expires_at' => 'datetime'];
    }

    public function scopeCurrent(Builder $q): Builder
    {
        return $q->where('expires_at', '>', now());
    }
}
