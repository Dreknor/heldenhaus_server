<?php

namespace App\Models;

use App\Enums\FamilyRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Family extends Model
{
    protected $fillable = ['name', 'login_code', 'timezone', 'settings'];

    protected function casts(): array
    {
        return ['settings' => 'array'];
    }

    protected static function booted(): void
    {
        static::creating(function (Family $family) {
            $family->login_code ??= static::generateLoginCode();
        });
    }

    public static function generateLoginCode(): string
    {
        do {
            $code = Str::upper(Str::random(6));
        } while (static::where('login_code', $code)->exists());

        return $code;
    }

    public function members(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function parents(): HasMany
    {
        return $this->members()->where('family_role', FamilyRole::Parent);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function invites(): HasMany
    {
        return $this->hasMany(FamilyInvite::class);
    }

    public function rewardItems(): HasMany
    {
        return $this->hasMany(RewardItem::class);
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings, $key, $default);
    }
}
