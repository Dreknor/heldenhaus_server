<?php

namespace App\Domain\Housing;

use App\Domain\Gameplay\GameException;
use App\Domain\Social\MessageService;
use App\Models\FamilyAlliance;
use App\Models\GuestbookEntry;
use App\Models\User;

/**
 * Besuche und Gästebuch (Konzept §2.6): Bündnis-Familien sehen das Haus nur an und schreiben ins Gästebuch.
 * Freitext gelten dieselben Regeln wie bei Nachrichten im Bündnis (Kinder unter 13 nur Vorlagen und Emojis).
 */
class GuestbookService
{
    /** Bündnis zwischen besuchender und besuchter Familie, null = eigene Familie. */
    public static function visitAlliance(User $user, int $familyId): ?FamilyAlliance
    {
        if ($user->family_id === $familyId) {
            return null;
        }

        return FamilyAlliance::active()->of($familyId)->get()->first(fn (FamilyAlliance $a) => $a->involves($user->family_id))
            ?? throw new GameException('Besuchen kann man nur verbündete Familien.');
    }

    public function write(User $user, int $familyId, string $kind, string $body): GuestbookEntry
    {
        $alliance = self::visitAlliance($user, $familyId);

        $body = match ($kind) {
            'preset' => array_key_exists($body, MessageService::PRESETS) ? $body : throw new GameException('Diesen Gruß gibt es nicht.'),
            'emoji' => in_array($body, MessageService::EMOJIS, true) ? $body : throw new GameException('Dieses Emoji gibt es hier nicht.'),
            'text' => MessageService::canUseText($user, $alliance)
                ? (trim($body) !== '' ? mb_substr(trim($body), 0, 300) : throw new GameException('Der Eintrag ist leer.'))
                : throw new GameException('Ins Gästebuch kannst du vorgefertigte Grüße und Emojis schreiben.'),
            default => throw new GameException('Unbekannte Eintragsart.'),
        };

        return GuestbookEntry::create(['family_id' => $familyId, 'user_id' => $user->id, 'kind' => $kind, 'body' => $body]);
    }

    public function delete(User $user, GuestbookEntry $entry): void
    {
        if ($entry->user_id !== $user->id && ! ($user->isParent() && $user->family_id === $entry->family_id)) {
            throw new GameException('Diesen Eintrag kannst du nicht löschen.');
        }
        $entry->delete();
    }

    public static function presentEntry(GuestbookEntry $e): array
    {
        return [
            'id' => $e->id,
            'kind' => $e->kind,
            'body' => $e->body,
            'text' => $e->kind === 'preset' ? (MessageService::PRESETS[$e->body] ?? $e->body) : $e->body,
            'author' => ['id' => $e->user_id, 'name' => $e->user?->name, 'family_id' => $e->user?->family_id],
            'created_at' => $e->created_at->toIso8601String(),
        ];
    }
}
