<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RewardItem extends Model
{
    protected $attributes = ['active' => true, 'requires_approval' => true, 'icon' => 'gift'];

    protected $fillable = ['family_id', 'title', 'description', 'icon', 'star_cost', 'allowance_cents', 'limit_per_week', 'requires_approval', 'ha_action_key', 'active'];

    protected function casts(): array
    {
        return [
            'star_cost' => 'integer',
            'limit_per_week' => 'integer',
            'requires_approval' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(RewardRedemption::class);
    }
}
