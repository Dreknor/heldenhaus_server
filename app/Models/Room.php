<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Room extends Model
{
    public const TYPES = [
        'living' => 'Wohnraum',
        'bedroom' => 'Zimmer',
        'workshop' => 'Werkstatt',
        'stable' => 'Stall',
        'library' => 'Bibliothek',
        'storage' => 'Lagerraum',
        'garden' => 'Garten',
        'trophy' => 'Trophäenhalle',
    ];

    protected $fillable = ['home_id', 'type', 'owner_user_id', 'name', 'width', 'height', 'version'];

    protected function casts(): array
    {
        return ['width' => 'integer', 'height' => 'integer', 'version' => 'integer'];
    }

    public function home(): BelongsTo
    {
        return $this->belongsTo(Home::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function placedItems(): HasMany
    {
        return $this->hasMany(PlacedItem::class);
    }
}
