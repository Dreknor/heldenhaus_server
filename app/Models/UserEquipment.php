<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserEquipment extends Model
{
    protected $table = 'user_equipment';

    protected $fillable = ['user_id', 'slot', 'item_id'];

    protected function casts(): array
    {
        return [];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
