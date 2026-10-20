<?php

namespace App\Domain\Family;

use App\Domain\Gameplay\GameException;
use App\Enums\FamilyRole;
use App\Events\MemberJoined;
use App\Models\Family;
use App\Models\FamilyInvite;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class FamilyService
{
    public function createFamily(User $founder, string $name): Family
    {
        return DB::transaction(function () use ($founder, $name) {
            $family = Family::create(['name' => $name, 'timezone' => $founder->timezone ?: 'Europe/Berlin']);
            $founder->update(['family_id' => $family->id, 'family_role' => FamilyRole::Parent]);

            return $family;
        });
    }

    public function createInvite(User $parent, FamilyRole $role, ?string $email = null): FamilyInvite
    {
        return $parent->family->invites()->create([
            'created_by' => $parent->id,
            'role' => $role,
            'email' => $email,
        ]);
    }

    public function acceptInvite(FamilyInvite $invite, User $user): void
    {
        DB::transaction(function () use ($invite, $user) {
            $invite = FamilyInvite::lockForUpdate()->findOrFail($invite->id);

            if (! $invite->isUsable()) {
                throw new GameException('Diese Einladung ist abgelaufen oder wurde schon benutzt.');
            }

            $user->update(['family_id' => $invite->family_id, 'family_role' => $invite->role]);
            $invite->update(['used_by' => $user->id, 'used_at' => now()]);
        });

        event(new MemberJoined($user->refresh()));
    }

    /** Kinderprofil ohne E-Mail: Anmeldung über Familiencode + PIN. */
    public function addChild(User $parent, string $name, int $birthYear, string $pin): User
    {
        $child = User::create([
            'name' => $name,
            'family_id' => $parent->family_id,
            'family_role' => FamilyRole::Child,
            'birth_year' => $birthYear,
            'pin' => $pin,
            'timezone' => $parent->timezone,
            'day_start' => $parent->day_start,
            'theme' => $birthYear <= now()->year - 13 ? 'teen' : 'kids',
        ]);
        event(new MemberJoined($child));

        return $child;
    }

    public function attemptPinLogin(Family $family, int $userId, string $pin): ?User
    {
        $user = $family->members()->whereKey($userId)->first();

        return $user && $user->pin && Hash::check($pin, $user->pin) ? $user : null;
    }
}
