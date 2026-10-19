<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FamilyQuestContribution extends Model
{
    protected $table = 'family_quest_contributions';

    protected $fillable = ['family_quest_id', 'user_id', 'task_completion_id', 'damage'];

    protected function casts(): array
    {
        return ['damage' => 'float'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
