<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DungeonPosition extends Model
{
    protected $fillable = ['dungeon_id', 'user_id', 'room', 'previous_room', 'hp', 'fight', 'knockouts'];

    protected function casts(): array
    {
        return ['room' => 'integer', 'previous_room' => 'integer', 'hp' => 'float', 'fight' => 'array', 'knockouts' => 'integer'];
    }

    public function dungeon(): BelongsTo
    {
        return $this->belongsTo(Dungeon::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
