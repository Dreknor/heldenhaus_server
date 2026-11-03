<?php

namespace App\Http\Presenters;

use App\Domain\Dungeons\DungeonCatalog;
use App\Domain\Features\Features;
use App\Models\Dungeon;
use App\Models\GardenPlot;
use App\Models\HaAuditEntry;
use App\Models\HaWebhook;
use App\Models\User;

/**
 * Kurzer Überblick über Dungeon, Garten und Home Assistant für die Karten auf dem Helden-Board.
 */
final class HubSummary
{
    public static function for(User $user): array
    {
        return [
            'dungeon' => self::dungeon($user),
            'garden' => self::garden($user),
            'homeAssistant' => self::homeAssistant($user),
        ];
    }

    private static function dungeon(User $user): array
    {
        $unlocked = Features::has($user, 'dungeons') || Features::has($user, 'family_dungeon');
        $dungeon = $user->family_id === null ? null : Dungeon::where('status', 'active')
            ->where(fn ($q) => $q->where('family_id', $user->family_id)->where('kind', 'family')->orWhere('user_id', $user->id))
            ->latest('id')
            ->first();

        $rooms = count($dungeon?->state['rooms'] ?? []);
        $revealed = count($dungeon?->state['revealed'] ?? []);

        return [
            'unlocked' => $unlocked,
            'unlockLevel' => Features::levelFor('dungeons', $user),
            'active' => $dungeon === null ? null : [
                'theme' => DungeonCatalog::theme($dungeon->theme)['name'],
                'icon' => DungeonCatalog::theme($dungeon->theme)['icon'],
                'kind' => $dungeon->kind,
                'rooms' => $rooms,
                'revealed' => $revealed,
                'bossUnlocked' => $dungeon->boss_unlocked,
            ],
        ];
    }

    private static function garden(User $user): array
    {
        $plots = $user->family_id === null ? collect() : GardenPlot::where('family_id', $user->family_id)->get();

        return [
            'unlocked' => Features::has($user, 'garden'),
            'unlockLevel' => Features::levelFor('garden', $user),
            'planted' => $plots->whereNotNull('plant_key')->count(),
            'ready' => $plots->filter(fn (GardenPlot $p) => $p->plant_key !== null && $p->ready_at !== null && $p->ready_at->isPast())->count(),
        ];
    }

    private static function homeAssistant(User $user): array
    {
        if ($user->family_id === null) {
            return ['connected' => false, 'lastEventAt' => null];
        }
        $last = HaAuditEntry::where('family_id', $user->family_id)->latest('id')->first();

        return [
            'connected' => $last !== null || HaWebhook::where('family_id', $user->family_id)->where('active', true)->exists(),
            'lastEventAt' => $last?->created_at?->toIso8601String(),
        ];
    }
}
