<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FamilyAlliance extends Model
{
    protected $fillable = ['family_id', 'partner_family_id', 'code', 'status', 'created_by', 'accepted_by', 'expires_at', 'accepted_at', 'ended_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'accepted_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    public function partnerFamily(): BelongsTo
    {
        return $this->belongsTo(Family::class, 'partner_family_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /** Bündnisse, an denen eine Familie beteiligt ist. */
    public function scopeOf(Builder $query, int $familyId): Builder
    {
        return $query->where(fn ($q) => $q->where('family_id', $familyId)->orWhere('partner_family_id', $familyId));
    }

    public function involves(?int $familyId): bool
    {
        return $familyId !== null && in_array($familyId, [$this->family_id, $this->partner_family_id], true);
    }

    public function otherFamilyId(int $familyId): ?int
    {
        return $this->family_id === $familyId ? $this->partner_family_id : $this->family_id;
    }

    /** IDs aller Familien, mit denen eine Familie verbündet ist. */
    public static function alliedFamilyIds(int $familyId): array
    {
        return static::active()->of($familyId)->get()->map(fn (self $a) => $a->otherFamilyId($familyId))->filter()->values()->all();
    }
}
