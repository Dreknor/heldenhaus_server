<?php

namespace App\Domain\Social;

use App\Domain\Gameplay\GameException;
use App\Models\FamilyAlliance;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Familienbündnis (Konzept §3.6): Zwei Familien verbinden sich, wenn beide Elternteile zustimmen –
 * Familie A erzeugt einen Bündnis-Code, ein Elternteil von Familie B löst ihn ein.
 */
class AllianceService
{
    public const VALID_DAYS = 7;

    public function __construct(private readonly FeedService $feed) {}

    public function invite(User $parent): FamilyAlliance
    {
        $this->ensureParent($parent);

        // Höchstens ein offener Code je Familie
        FamilyAlliance::where('family_id', $parent->family_id)->where('status', 'pending')->delete();

        return FamilyAlliance::create([
            'family_id' => $parent->family_id,
            'code' => $this->code(),
            'status' => 'pending',
            'created_by' => $parent->id,
            'expires_at' => now()->addDays(self::VALID_DAYS),
        ]);
    }

    public function join(User $parent, string $code): FamilyAlliance
    {
        $this->ensureParent($parent);

        return DB::transaction(function () use ($parent, $code) {
            $alliance = FamilyAlliance::with('family')->where('code', Str::upper(trim($code)))->where('status', 'pending')
                ->where('expires_at', '>', now())->lockForUpdate()->first();

            if ($alliance === null) {
                throw new GameException('Diesen Bündnis-Code gibt es nicht (mehr).');
            }
            if ($alliance->family_id === $parent->family_id) {
                throw new GameException('Mit der eigenen Familie kann man sich nicht verbünden.');
            }
            if (in_array($alliance->family_id, FamilyAlliance::alliedFamilyIds($parent->family_id), true)) {
                throw new GameException('Ihr seid schon verbündet.');
            }

            $alliance->update([
                'partner_family_id' => $parent->family_id,
                'status' => 'active',
                'code' => null,
                'accepted_by' => $parent->id,
                'accepted_at' => now(),
            ]);

            $other = $alliance->family->name;
            $mine = $parent->family->name;
            $this->feed->post($alliance->family_id, null, 'alliance', '🤝', "Neues Bündnis mit „{$mine}“!");
            $this->feed->post($parent->family_id, null, 'alliance', '🤝', "Neues Bündnis mit „{$other}“!");

            return $alliance;
        });
    }

    public function end(User $parent, FamilyAlliance $alliance): void
    {
        $this->ensureParent($parent);
        if (! $alliance->involves($parent->family_id)) {
            throw new GameException('Das ist kein Bündnis deiner Familie.');
        }

        $alliance->status === 'pending'
            ? $alliance->delete()
            : $alliance->update(['status' => 'ended', 'ended_at' => now()]);
    }

    public static function present(int $familyId, bool $isParent): array
    {
        $alliances = FamilyAlliance::with('family:id,name', 'partnerFamily:id,name')->of($familyId)->whereIn('status', ['active', 'pending'])->latest()->get();

        return [
            'active' => $alliances->where('status', 'active')->map(function (FamilyAlliance $a) use ($familyId) {
                $other = $a->family_id === $familyId ? $a->partnerFamily : $a->family;

                return [
                    'id' => $a->id,
                    'family_id' => $other?->id, // für Hausbesuche: families/{family}/home
                    'family' => $other?->name,
                    'members' => $other ? $other->members()->where('family_role', '!=', 'guest')->count() : 0,
                    'since' => $a->accepted_at?->toDateString(),
                ];
            })->values(),
            'invite' => $isParent ? $alliances->where('status', 'pending')->where('family_id', $familyId)->where('expires_at', '>', now())
                ->map(fn (FamilyAlliance $a) => ['id' => $a->id, 'code' => $a->code, 'expires_at' => $a->expires_at->toDateString()])->first() : null,
        ];
    }

    private function ensureParent(User $user): void
    {
        if (! $user->isParent()) {
            throw new GameException('Bündnisse schließen nur Eltern.');
        }
    }

    private function code(): string
    {
        do {
            $code = Str::upper(Str::random(8));
        } while (FamilyAlliance::where('code', $code)->exists());

        return $code;
    }
}
