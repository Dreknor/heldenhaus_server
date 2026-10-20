<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserItem extends Model
{
    protected $fillable = ['user_id', 'item_id', 'quantity', 'master_quantity'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'master_quantity' => 'integer'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
