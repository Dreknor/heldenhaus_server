<?php

namespace App\Domain\Social;

use App\Domain\Gameplay\GameException;
use App\Enums\AgeGroup;
use App\Enums\FamilyRole;
use App\Models\FamilyAlliance;
use App\Models\Message;
use App\Models\User;

/**
 * Nachrichten in der Familie und im Bündnis (Konzept §3.6). Familienübergreifend sehen Kinder unter 13
 * keinen Freitext, nur vorgefertigte Nachrichten und Emojis. Teens bekommen Freitext im Bündnis nur,
 * wenn die Eltern ihn freigeben (chat_allowed). In der eigenen Familie dürfen alle schreiben.
 */
class MessageService
{
    public const PRESETS = [
        'hello' => 'Hallo! 👋',
        'well_done' => 'Gut gemacht! 👍',
        'keep_going' => 'Weiter so! 💪',
        'thanks' => 'Danke! ❤️',
        'lets_go' => 'Los geht’s! 🚀',
        'almost' => 'Fast geschafft! 🏁',
        'good_night' => 'Gute Nacht! 🌙',
        'boss' => 'Den Boss schaffen wir! ⚔️',
    ];

    public const EMOJIS = ['👍', '🔥', '⭐', '💪', '🎉', '❤️', '😂', '🏆', '🐉', '🌟'];

    public static function canUseText(User $user, ?FamilyAlliance $alliance): bool
    {
        if ($alliance === null) {
            return true;
        }

        return match ($user->ageGroup()) {
            AgeGroup::Adult => true,
            AgeGroup::Teen => (bool) $user->chat_allowed,
            AgeGroup::Child => false,
        };
    }

    public function send(User $user, ?FamilyAlliance $alliance, string $kind, string $body): Message
    {
        $this->ensureChannel($user, $alliance);

        $body = match ($kind) {
            'preset' => array_key_exists($body, self::PRESETS) ? $body : throw new GameException('Diese Nachricht gibt es nicht.'),
            'emoji' => in_array($body, self::EMOJIS, true) ? $body : throw new GameException('Dieses Emoji gibt es hier nicht.'),
            'text' => self::canUseText($user, $alliance)
                ? (trim($body) !== '' ? trim($body) : throw new GameException('Die Nachricht ist leer.'))
                : throw new GameException('Im Bündnis kannst du vorgefertigte Nachrichten und Emojis schicken.'),
            default => throw new GameException('Unbekannte Nachrichtenart.'),
        };

        return Message::create([
            'family_id' => $user->family_id,
            'alliance_id' => $alliance?->id,
            'user_id' => $user->id,
            'kind' => $kind,
            'body' => mb_substr($body, 0, 500),
        ]);
    }

    public function list(User $viewer, ?FamilyAlliance $alliance, ?int $afterId = null, int $limit = 50): array
    {
        $this->ensureChannel($viewer, $alliance);
        $canReadText = self::canUseText($viewer, $alliance);

        return Message::with('user:id,name,avatar_color', 'family:id,name')
            ->when($alliance, fn ($q) => $q->where('alliance_id', $alliance->id), fn ($q) => $q->whereNull('alliance_id')->where('family_id', $viewer->family_id))
            ->when($afterId, fn ($q, $id) => $q->where('id', '>', $id))
            ->latest('id')->limit($limit)->get()->reverse()->values()
            ->map(fn (Message $m) => [
                'id' => $m->id,
                'kind' => $m->kind,
                'text' => match (true) {
                    $m->kind === 'preset' => self::PRESETS[$m->body] ?? '…',
                    $m->kind === 'text' && ! $canReadText => null,
                    default => $m->body,
                },
                'hidden' => $m->kind === 'text' && ! $canReadText,
                'user' => $m->user?->only(['id', 'name', 'avatar_color']),
                'family' => $alliance ? $m->family?->name : null,
                'mine' => $m->user_id === $viewer->id,
                'created_at' => $m->created_at->toIso8601String(),
            ])
            ->all();
    }

    public static function resolveAlliance(User $user, ?int $allianceId): ?FamilyAlliance
    {
        if ($allianceId === null) {
            return null;
        }

        $alliance = FamilyAlliance::active()->find($allianceId);
        if ($alliance === null || ! $alliance->involves($user->family_id)) {
            throw new GameException('Dieses Bündnis gibt es nicht.');
        }

        return $alliance;
    }

    private function ensureChannel(User $user, ?FamilyAlliance $alliance): void
    {
        if ($alliance !== null && (! $alliance->involves($user->family_id) || $alliance->status !== 'active')) {
            throw new GameException('Dieses Bündnis gibt es nicht.');
        }
        if ($alliance !== null && $user->family_role === FamilyRole::Guest) {
            throw new GameException('Gäste schreiben nur in der eigenen Familie.');
        }
    }
}
