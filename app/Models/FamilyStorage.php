<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Familienlager: Materialien, die alle in der Familie nutzen dürfen. */
class FamilyStorage extends Model
{
    protected $table = 'family_storage';

    protected $fillable = ['family_id', 'item_id', 'quantity'];

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
