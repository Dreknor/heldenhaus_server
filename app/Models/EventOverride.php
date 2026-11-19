<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Admin-Änderung an einem Event (kind „event“) oder Zufallsereignis (kind „fun“): nur die geänderten Felder. */
class EventOverride extends Model
{
    public const KINDS = ['event', 'fun'];

    protected $fillable = ['kind', 'key', 'data', 'updated_by'];

    protected function casts(): array
    {
        return ['data' => 'array'];
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
