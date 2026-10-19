<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FamilyQuest extends Model
{
    protected $table = 'family_quests';

    protected $fillable = ['family_id', 'boss_key', 'name', 'icon', 'max_hp', 'hp', 'reward_text', 'status', 'ends_at', 'defeated_at', 'started_by'];

    protected function casts(): array
    {
        return ['max_hp' => 'integer', 'hp' => 'float', 'ends_at' => 'datetime', 'defeated_at' => 'datetime'];
    }

    public function contributions(): HasMany
    {
        return $this->hasMany(FamilyQuestContribution::class);
    }
}
