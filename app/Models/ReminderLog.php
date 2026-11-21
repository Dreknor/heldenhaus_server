<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Gesendete Erinnerung (§14.2): höchstens eine pro Aufgabe, Person und Tag. */
class ReminderLog extends Model
{
    protected $fillable = ['assignment_id', 'user_id', 'date', 'sent_at', 'completed', 'evaluated'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'sent_at' => 'datetime',
            'completed' => 'boolean',
            'evaluated' => 'boolean',
        ];
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(TaskAssignment::class);
    }
}
