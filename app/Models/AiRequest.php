<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiRequest extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'family_id', 'user_id', 'purpose', 'model', 'prompt_tokens', 'completion_tokens', 'cost_usd', 'ok',
        'response_payload', 'response_status', 'finish_reason', 'error_message',
    ];

    protected function casts(): array
    {
        return ['cost_usd' => 'float', 'ok' => 'boolean', 'response_status' => 'integer'];
    }
}
