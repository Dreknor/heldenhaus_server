<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Eine freigeschaltete Funktion (§13.4). Was einmal freigeschaltet ist, bleibt es. */
class FeatureUnlock extends Model
{
    protected $fillable = ['user_id', 'feature_key', 'unlocked_at', 'source', 'intro_shown_at', 'intro_seen_at', 'starter_done_at'];

    protected function casts(): array
    {
        return ['unlocked_at' => 'datetime', 'intro_shown_at' => 'datetime', 'intro_seen_at' => 'datetime', 'starter_done_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
