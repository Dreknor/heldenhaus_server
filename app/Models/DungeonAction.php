<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DungeonAction extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['dungeon_id', 'user_id', 'client_uuid', 'counter', 'action', 'room', 'torches', 'result'];

    protected function casts(): array
    {
        return ['result' => 'array', 'counter' => 'integer', 'room' => 'integer', 'torches' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
