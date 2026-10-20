<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlacedItem extends Model
{
    protected $fillable = ['room_id', 'item_id', 'placed_by', 'x', 'y', 'layer', 'rotation', 'mirrored', 'master'];

    protected function casts(): array
    {
        return ['x' => 'integer', 'y' => 'integer', 'rotation' => 'integer', 'mirrored' => 'boolean', 'master' => 'boolean'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
