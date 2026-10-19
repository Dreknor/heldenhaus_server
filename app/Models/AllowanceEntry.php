<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AllowanceEntry extends Model
{
    protected $table = 'allowance_entries';

    protected $fillable = ['family_id', 'user_id', 'amount_cents', 'description', 'created_by', 'reward_redemption_id'];

    protected function casts(): array
    {
        return ['amount_cents' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
