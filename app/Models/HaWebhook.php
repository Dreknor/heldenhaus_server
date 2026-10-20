<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HaWebhook extends Model
{
    protected $fillable = ['family_id', 'url', 'secret', 'events', 'active'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'events' => 'array',
            'active' => 'boolean',
            'last_sent_at' => 'datetime',
        ];
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    public function wants(string $event): bool
    {
        return $this->active && ($this->events === null || in_array($event, $this->events, true));
    }
}
