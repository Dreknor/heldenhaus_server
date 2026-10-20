<?php

namespace App\Models;

use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HaAuditEntry extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    protected $table = 'ha_audit_log';

    protected $fillable = ['family_id', 'direction', 'action', 'member_id', 'status', 'details', 'ip'];

    protected function casts(): array
    {
        return ['details' => 'array'];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'member_id');
    }

    /** Protokoll 90 Tage aufheben. */
    public function prunable()
    {
        return static::where('created_at', '<', now()->subDays(90));
    }
}
