<?php

namespace App\Domain\Rewards;

use App\Domain\Gameplay\GameException;
use App\Models\Family;
use App\Models\RewardItem;

/**
 * Vorlagen für den Belohnungsshop (database/content/rewards.json): Neue Familien starten mit den
 * Start-Belohnungen, Eltern übernehmen weitere mit einem Tipp und passen Preis und Limit danach an.
 */
class RewardTemplates
{
    public const FIELDS = ['title', 'description', 'icon', 'star_cost', 'limit_per_week', 'requires_approval', 'allowance_cents', 'ha_action_key'];

    public static function all(): array
    {
        static $templates;

        return $templates ??= collect(json_decode(file_get_contents(database_path('content/rewards.json')), true, flags: JSON_THROW_ON_ERROR))
            ->keyBy('key')->all();
    }

    /** Vorlagen mit Hinweis, ob die Familie sie (nach Titel) schon im Shop hat. */
    public static function forFamily(Family $family): array
    {
        $titles = $family->rewardItems()->pluck('title')->map(fn ($t) => mb_strtolower($t))->all();

        return collect(self::all())->map(fn ($t) => [
            ...$t,
            'adopted' => in_array(mb_strtolower($t['title']), $titles, true),
        ])->values()->all();
    }

    /**
     * Vorlagen in den Shop übernehmen; schon vorhandene (gleicher Titel) werden übersprungen.
     *
     * @param  list<string>  $keys
     * @return list<RewardItem>
     */
    public static function adopt(Family $family, array $keys): array
    {
        $titles = $family->rewardItems()->pluck('title')->map(fn ($t) => mb_strtolower($t))->all();
        $created = [];
        foreach (array_unique($keys) as $key) {
            $template = self::all()[$key] ?? throw new GameException("Unbekannte Vorlage „{$key}“.");
            if (in_array(mb_strtolower($template['title']), $titles, true)) {
                continue;
            }
            $created[] = $family->rewardItems()->create(array_intersect_key($template, array_flip(self::FIELDS)));
            $titles[] = mb_strtolower($template['title']);
        }

        return $created;
    }

    /** Start-Belohnungen für eine neue Familie. */
    public static function seedStarter(Family $family): void
    {
        self::adopt($family, collect(self::all())->where('starter', true)->keys()->all());
    }
}
