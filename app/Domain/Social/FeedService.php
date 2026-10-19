<?php

namespace App\Domain\Social;

use App\Domain\Gameplay\GameException;
use App\Models\FeedEntry;
use App\Models\FeedReaction;
use App\Models\User;

/**
 * Familien-Feed (Konzept §3.5): wer hat was geschafft, Reaktionen statt Freitext.
 * Persönliche (insbesondere private) Aufgaben erscheinen hier nie – nur Familienaufgaben und Spielereignisse.
 */
class FeedService
{
    public const EMOJIS = ['👍', '🔥', '⭐', '💪', '🎉', '❤️'];

    public function post(?int $familyId, ?User $user, string $type, string $icon, string $text, array $data = []): ?FeedEntry
    {
        if (! $familyId) {
            return null;
        }

        return FeedEntry::create([
            'family_id' => $familyId,
            'user_id' => $user?->id,
            'type' => $type,
            'icon' => $icon,
            'text' => $text,
            'data' => $data ?: null,
        ]);
    }

    /** Reaktion umschalten (nochmal tippen nimmt sie zurück). */
    public function react(User $user, FeedEntry $entry, string $emoji): bool
    {
        if (! in_array($emoji, self::EMOJIS, true)) {
            throw new GameException('Diese Reaktion gibt es nicht.');
        }
        if ($entry->family_id !== $user->family_id) {
            throw new GameException('Das ist nicht aus deiner Familie.');
        }

        $existing = FeedReaction::where(['feed_entry_id' => $entry->id, 'user_id' => $user->id, 'emoji' => $emoji])->first();
        if ($existing) {
            $existing->delete();

            return false;
        }

        FeedReaction::create(['feed_entry_id' => $entry->id, 'user_id' => $user->id, 'emoji' => $emoji]);

        return true;
    }

    public static function present(int $familyId, int $viewerId, int $limit = 30): array
    {
        return FeedEntry::with('user:id,name,avatar_color', 'reactions.user:id,name')
            ->where('family_id', $familyId)
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (FeedEntry $e) => [
                'id' => $e->id,
                'type' => $e->type,
                'icon' => $e->icon,
                'text' => $e->text,
                'user' => $e->user?->only(['id', 'name', 'avatar_color']),
                'created_at' => $e->created_at->toIso8601String(),
                'reactions' => collect(self::EMOJIS)->map(fn ($emoji) => [
                    'emoji' => $emoji,
                    'count' => $e->reactions->where('emoji', $emoji)->count(),
                    'mine' => $e->reactions->where('emoji', $emoji)->contains('user_id', $viewerId),
                    'names' => $e->reactions->where('emoji', $emoji)->pluck('user.name')->filter()->values(),
                ])->values(),
            ])
            ->all();
    }
}
