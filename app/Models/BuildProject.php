<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Familien-Bauprojekt: neuer Raum, Ausbaustufe oder Station (Konzept §2.6). */
class BuildProject extends Model
{
    protected $fillable = ['family_id', 'project_key', 'requirements', 'progress', 'status', 'reward_text', 'started_by', 'finishes_at', 'completed_at'];

    protected function casts(): array
    {
        return ['requirements' => 'array', 'progress' => 'array', 'finishes_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function contributions(): HasMany
    {
        return $this->hasMany(BuildContribution::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['collecting', 'building'], true);
    }
}
