<?php

namespace App\Domain\Achievements;

use App\Domain\Badges\BadgeService;
use App\Models\User;

/**
 * Titel bei Level 20, 30, 50, 75 und 100 (§14.7). Es gibt kein Level-Maximum: Danach steigt das Level
 * einfach weiter, der höchste Titel bleibt. Die Titel sind geschlechtsneutral formuliert.
 */
class TitleService
{
    public function __construct(private readonly BadgeService $badges) {}

    /** Höchster erreichter Level-Titel oder null. */
    public static function forLevel(int $level): ?array
    {
        return collect(LongTermCatalog::titles())->filter(fn (array $t) => $level >= $t['level'])->last();
    }

    /** Nächster Level-Titel (für „noch X Level bis …“). */
    public static function next(int $level): ?array
    {
        return collect(LongTermCatalog::titles())->first(fn (array $t) => $level < $t['level']);
    }

    /**
     * Alle bis zum aktuellen Level erreichten Titel als Abzeichen vergeben (genau einmal).
     *
     * @return list<string> neu erreichte Titel
     */
    public function sync(User $user, ?int $level = null): array
    {
        $level ??= (int) ($user->stats()->value('level') ?? 1);
        $new = [];

        foreach (LongTermCatalog::titles() as $t) {
            if ($level < $t['level']) {
                break;
            }
            $badge = $this->badges->award($user, $t['key'], 'title', $t['name'], $t['icon'], ['level' => $t['level']],
                "{$user->name} trägt jetzt den Titel „{$t['name']}“!");
            if ($badge !== null) {
                $new[] = $t['name'];
            }
        }

        return $new;
    }

    /**
     * Titelzeile unter dem Namen: Level-Titel und beste Meisterschaft.
     *
     * @return array{level: ?string, mastery: ?string}
     */
    public static function display(User $user, ?int $level = null): array
    {
        $level ??= (int) ($user->stats()->value('level') ?? 1);

        return ['level' => self::forLevel($level)['name'] ?? null, 'mastery' => MasteryService::bestTitle($user)];
    }
}
