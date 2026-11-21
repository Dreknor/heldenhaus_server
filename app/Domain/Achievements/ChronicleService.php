<?php

namespace App\Domain\Achievements;

use App\Models\ChronicleUnlock;
use App\Models\Family;
use App\Models\Quest;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Chronik des Heldenhauses (§14.8): Kapitel aus database/content/chronicle.json, freigeschaltet über das eigene
 * Level und besiegte Familien-Bosse. Der Text ist personalisiert ({family}, {hero}, {crest}). Neue Kapitel kommen
 * nur über die JSON-Datei – die App lädt sie über die API und braucht kein Update.
 */
class ChronicleService
{
    /** Wappen zur Auswahl in den Familieneinstellungen; ohne Wahl gilt das erste. */
    public const CRESTS = ['🛡️', '🦁', '🐉', '🦅', '🐺', '🦊', '🐻', '🦉', '🌳', '⭐', '🌙', '☀️', '🏰', '🌻', '🐢', '🦄'];

    private static ?array $chapters = null;

    /** @return list<array> nach Nummer sortiert */
    public static function chapters(): array
    {
        return self::$chapters ??= collect(json_decode(file_get_contents(database_path('content/chronicle.json')), true, flags: JSON_THROW_ON_ERROR))
            ->sortBy('number')->values()->all();
    }

    public static function flush(): void
    {
        self::$chapters = null;
    }

    public static function crest(?Family $family): string
    {
        return $family?->crest ?: self::CRESTS[0];
    }

    /** „Familie Müller“ – auch wenn die Familie nur „Müller“ heißt. */
    public static function familyName(?Family $family): string
    {
        $name = trim($family?->name ?? '');
        if ($name === '') {
            return 'die Heldenfamilie';
        }

        return Str::startsWith(Str::lower($name), 'familie') ? $name : "Familie {$name}";
    }

    public static function personalize(string $text, User $user): string
    {
        return strtr($text, [
            '{family}' => self::familyName($user->family),
            '{hero}' => $user->name,
            '{crest}' => self::crest($user->family),
        ]);
    }

    /** Besiegte Familien-Bosse (gemeinsame Quests). */
    public static function bossesDefeated(?int $familyId): int
    {
        return $familyId ? Quest::where('family_id', $familyId)->where('scope', 'family')->where('type', 'boss')->where('status', 'completed')->count() : 0;
    }

    public static function isUnlockable(array $chapter, int $level, int $bosses): bool
    {
        return $level >= (int) ($chapter['unlock']['level'] ?? 1) && $bosses >= (int) ($chapter['unlock']['bosses'] ?? 0);
    }

    /**
     * Neu erreichte Kapitel freischalten.
     *
     * @return list<string> Schlüssel der neuen Kapitel
     */
    public function sync(User $user): array
    {
        $level = (int) ($user->stats()->value('level') ?? 1);
        $bosses = self::bossesDefeated($user->family_id);
        $have = ChronicleUnlock::where('user_id', $user->id)->pluck('chapter_key')->all();
        $new = [];

        foreach (self::chapters() as $chapter) {
            if (! in_array($chapter['key'], $have, true) && self::isUnlockable($chapter, $level, $bosses)) {
                ChronicleUnlock::firstOrCreate(['user_id' => $user->id, 'chapter_key' => $chapter['key']], ['unlocked_at' => now()]);
                $new[] = $chapter['key'];
            }
        }

        return $new;
    }

    /** Kapitel als gelesen markieren (nur freigeschaltete). */
    public function markRead(User $user, string $key): bool
    {
        return ChronicleUnlock::where('user_id', $user->id)->where('chapter_key', $key)->whereNull('read_at')->update(['read_at' => now()]) > 0;
    }

    /**
     * Familienrat (§14.4): Die Familie „schlägt“ gemeinsam das nächste Kapitel auf – das erste, das mindestens ein
     * Mitglied freigeschaltet, aber noch nicht alle gelesen haben. Es wird für alle aktiven Mitglieder freigeschaltet
     * und als gelesen markiert.
     *
     * @return array|null das aufgeschlagene Kapitel (personalisiert für das erste Elternteil) oder null
     */
    public function openTogether(Family $family): ?array
    {
        $members = $family->members()->get();
        if ($members->isEmpty()) {
            return null;
        }
        foreach ($members as $member) {
            $this->sync($member);
        }

        $unlocks = ChronicleUnlock::whereIn('user_id', $members->pluck('id'))->get()->groupBy('chapter_key');
        $chapter = collect(self::chapters())->first(function (array $c) use ($unlocks, $members) {
            $rows = $unlocks->get($c['key']);

            return $rows !== null && $rows->whereNotNull('read_at')->count() < $members->count();
        });
        if ($chapter === null) {
            return null;
        }

        foreach ($members as $member) {
            $row = ChronicleUnlock::firstOrNew(['user_id' => $member->id, 'chapter_key' => $chapter['key']]);
            $row->unlocked_at ??= now();
            $row->read_at ??= now();
            $row->opened_together_at = now();
            $row->save();
        }

        $reader = $members->firstWhere(fn (User $m) => $m->isParent()) ?? $members->first();

        return self::presentChapter($chapter, $reader, ChronicleUnlock::where('user_id', $reader->id)->where('chapter_key', $chapter['key'])->first());
    }

    public static function presentChapter(array $chapter, User $user, ?ChronicleUnlock $unlock): array
    {
        $open = $unlock !== null;

        return [
            'key' => $chapter['key'],
            'number' => $chapter['number'],
            'title' => $chapter['title'],
            'image' => $chapter['image'] ?? null,
            'unlock' => ['level' => (int) ($chapter['unlock']['level'] ?? 1), 'bosses' => (int) ($chapter['unlock']['bosses'] ?? 0)],
            'unlocked' => $open,
            'read' => $unlock?->read_at !== null,
            'together' => $unlock?->opened_together_at !== null,
            'unlocked_at' => $unlock?->unlocked_at?->toIso8601String(),
            // Gesperrte Kapitel verraten ihren Text noch nicht
            'text' => $open ? array_map(fn (string $p) => self::personalize($p, $user), $chapter['text']) : [],
        ];
    }

    public function present(User $user): array
    {
        $this->sync($user);
        $unlocks = ChronicleUnlock::where('user_id', $user->id)->get()->keyBy('chapter_key');

        return [
            'chapters' => collect(self::chapters())->map(fn (array $c) => self::presentChapter($c, $user, $unlocks->get($c['key'])))->all(),
            'level' => (int) ($user->stats()->value('level') ?? 1),
            'bosses' => self::bossesDefeated($user->family_id),
            'family' => self::familyName($user->family),
            'crest' => self::crest($user->family),
        ];
    }

    /** Erstes ungelesenes Kapitel (für den Hinweis im Heldenpanel). */
    public static function firstUnread(User $user): ?array
    {
        $unread = ChronicleUnlock::where('user_id', $user->id)->whereNull('read_at')->pluck('chapter_key')->all();
        $chapter = collect(self::chapters())->first(fn (array $c) => in_array($c['key'], $unread, true));

        return $chapter ? ['key' => $chapter['key'], 'number' => $chapter['number'], 'title' => $chapter['title']] : null;
    }
}
