<?php

namespace App\Domain\Badges;

use App\Domain\Social\FeedService;
use App\Models\User;
use App\Models\UserBadge;

/**
 * Abzeichen aus §14 (Ziele, Meistern, Ehrensache, Heldenjahr, Alben). Jedes Abzeichen gibt es je Person
 * genau einmal (Schlüssel eindeutig); es erscheint in der Trophäenhalle und auf Wunsch im Familien-Feed.
 */
class BadgeService
{
    public const KINDS = [
        'goal' => 'Ziel erreicht',
        'mastery' => 'Gewohnheit gemeistert',
        'reliable' => 'Verlässlich',
        'year' => 'Heldenjahr',
        'album' => 'Sammelalbum',
        'category' => 'Meisterschaft',
        'title' => 'Titel',
    ];

    public function __construct(private readonly FeedService $feed) {}

    /** Vergibt das Abzeichen, falls die Person es noch nicht hat; sonst null. */
    public function award(User $user, string $key, string $kind, string $name, string $icon, array $data = [], ?string $feedText = null): ?UserBadge
    {
        $badge = UserBadge::firstOrNew(['user_id' => $user->id, 'key' => $key]);
        if ($badge->exists) {
            return null;
        }

        $badge->fill(['kind' => $kind, 'name' => $name, 'icon' => $icon, 'data' => $data ?: null])->save();

        if ($feedText !== null) {
            $this->feed->post($user->family_id, $user, 'badge', $icon, $feedText, ['badge' => $key]);
        }

        return $badge;
    }

    public static function present(UserBadge $badge): array
    {
        return [
            'key' => $badge->key,
            'kind' => $badge->kind,
            'kind_label' => self::KINDS[$badge->kind] ?? $badge->kind,
            'name' => $badge->name,
            'icon' => $badge->icon,
            'data' => $badge->data ?? [],
            'awarded_at' => $badge->created_at?->toDateString(),
            'new' => $badge->seen_at === null,
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function forUser(User $user): array
    {
        return UserBadge::where('user_id', $user->id)->latest('id')->get()->map(fn (UserBadge $b) => self::present($b))->values()->all();
    }

    public static function markSeen(User $user): int
    {
        return UserBadge::where('user_id', $user->id)->whereNull('seen_at')->update(['seen_at' => now()]);
    }
}
