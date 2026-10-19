<?php

namespace App\Models;

use App\Enums\FamilyRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class FamilyInvite extends Model
{
    protected $fillable = ['family_id', 'created_by', 'code', 'role', 'email', 'expires_at', 'used_by', 'used_at'];

    protected function casts(): array
    {
        return [
            'role' => FamilyRole::class,
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (FamilyInvite $invite) {
            $invite->code ??= Str::upper(Str::random(10));
            $invite->expires_at ??= now()->addDays(config('heldenhaus.invite_valid_days'));
        });
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    public function isUsable(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }

    public static function findUsable(?string $code): ?self
    {
        if (! $code) {
            return null;
        }

        $invite = static::where('code', Str::upper(trim($code)))->first();

        return $invite?->isUsable() ? $invite : null;
    }
}
