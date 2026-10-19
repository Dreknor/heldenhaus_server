<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPet extends Model
{
    protected $table = 'user_pets';

    protected $fillable = ['user_id', 'egg_item_id', 'potion_item_id', 'name', 'growth', 'is_mount'];

    protected function casts(): array
    {
        return ['growth' => 'integer', 'is_mount' => 'boolean'];
    }

    public function egg(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'egg_item_id');
    }

    public function potion(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'potion_item_id');
    }
}
