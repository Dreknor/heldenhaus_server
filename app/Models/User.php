<?php

namespace App\Models;

use App\Enums\AgeGroup;
use App\Enums\FamilyRole;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'pin',
        'family_id',
        'family_role',
        'birth_year',
        'timezone',
        'day_start',
        'theme',
        'avatar_color',
        'avatar',
    ];

    protected $hidden = [
        'password',
        'pin',
        'remember_token',
    ];

    protected $appends = ['age_group'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'pin' => 'hashed',
            'family_role' => FamilyRole::class,
            'birth_year' => 'integer',
            'day_start' => 'integer',
            'is_admin' => 'boolean',
            'avatar' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (User $user) {
            $user->stats()->firstOrCreate([], [
                'hp' => config('game.max_hp'),
                'mp' => config('game.max_mp_base'),
            ]);
        });
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    public function stats(): HasOne
    {
        return $this->hasOne(UserStats::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(TaskAssignment::class);
    }

    public function completions(): HasMany
    {
        return $this->hasMany(TaskCompletion::class);
    }

    public function achievements(): HasMany
    {
        return $this->hasMany(UserAchievement::class);
    }

    public function deviceTokens(): HasMany
    {
        return $this->hasMany(DeviceToken::class);
    }

    public function notificationSetting(): HasOne
    {
        return $this->hasOne(NotificationSetting::class);
    }

    public function getAgeGroupAttribute(): string
    {
        return $this->ageGroup()->value;
    }

    public function ageGroup(): AgeGroup
    {
        return AgeGroup::fromBirthYear($this->birth_year);
    }

    public function isParent(): bool
    {
        return $this->family_role === FamilyRole::Parent;
    }

    public function isChild(): bool
    {
        return $this->family_role === FamilyRole::Child;
    }

    public function sameFamilyAs(?User $other): bool
    {
        return $other !== null && $this->family_id !== null && $this->family_id === $other->family_id;
    }

    /** Aktueller Spieltag unter Berücksichtigung von Zeitzone und Tagesbeginn. */
    public function gameDate(?\DateTimeInterface $at = null): CarbonImmutable
    {
        $local = CarbonImmutable::instance($at ?? now())->setTimezone($this->timezone ?: 'Europe/Berlin');

        return $local->subHours($this->day_start ?? 0)->startOfDay();
    }
}
