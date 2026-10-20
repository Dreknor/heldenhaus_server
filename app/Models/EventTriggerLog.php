<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventTriggerLog extends Model
{
    protected $table = 'event_trigger_log';

    public const UPDATED_AT = null;

    protected $fillable = ['family_id', 'event_key', 'trigger', 'result', 'context'];

    protected function casts(): array
    {
        return ['context' => 'array'];
    }
}
