<?php

namespace App\Models;

use App\Domain\Events\EventCatalog;
use Illuminate\Database\Eloquent\Model;

/** Ein Zufallsereignis (§12.5) für eine Person oder die ganze Familie. */
class FunEventInstance extends Model
{
    protected $fillable = ['family_id', 'user_id', 'key', 'seed', 'status', 'data', 'visible_from', 'expires_at', 'claimed_by', 'claimed_at'];

    protected function casts(): array
    {
        return ['data' => 'array', 'visible_from' => 'datetime', 'expires_at' => 'datetime', 'claimed_at' => 'datetime'];
    }

    public function definition(): array
    {
        return EventCatalog::fun()[$this->key] ?? [];
    }
}
