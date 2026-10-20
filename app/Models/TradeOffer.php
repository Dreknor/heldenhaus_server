<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Tauschangebot an eine Bündnis-Familie, nur Gegenstände (Konzept §2.5). */
class TradeOffer extends Model
{
    protected $fillable = ['user_id', 'family_id', 'alliance_id', 'offer', 'want', 'status', 'accepted_by', 'approved_by', 'completed_at'];

    protected function casts(): array
    {
        return ['offer' => 'array', 'want' => 'array', 'completed_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function acceptor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }

    public function alliance(): BelongsTo
    {
        return $this->belongsTo(FamilyAlliance::class);
    }
}
