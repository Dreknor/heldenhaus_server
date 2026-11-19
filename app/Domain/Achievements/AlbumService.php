<?php

namespace App\Domain\Achievements;

use App\Domain\Badges\BadgeService;
use App\Domain\Crafting\Catalog;
use App\Domain\Events\EventCatalog;
use App\Domain\Quests\QuestService;
use App\Enums\FamilyRole;
use App\Models\AlbumProgress;
use App\Models\Item;
use App\Models\PlacedItem;
use App\Models\Quest;
use App\Models\User;
use App\Models\UserAchievement;
use App\Models\UserBadge;
use App\Models\UserCrafting;
use App\Models\UserItem;
use App\Models\UserPet;
use App\Models\UserRecipe;
use Illuminate\Support\Facades\DB;

/**
 * Sammelalbum (§14.7): Haustiere, Reittiere, Monster-Lexikon, Möbel, Rezepte und Abzeichen mit Fortschritt
 * in Prozent. Einmal Gesammeltes bleibt im Album. Bei 25/50/75/100 % gibt es je Album genau einmal Gold,
 * teils einen Gegenstand und ein Abzeichen. Event-Einträge sind gekennzeichnet – sie kommen mit ihrem Event wieder.
 */
class AlbumService
{
    public function __construct(private readonly BadgeService $badges) {}

    /**
     * Alle Einträge eines Albums: Schlüssel, Name, Symbol und Event (falls nur im Event zu bekommen).
     *
     * @return list<array{key: string, name: string, icon: string, event: ?array{key: string, name: string}}>
     */
    public static function entries(string $album): array
    {
        $event = function (?string $key): ?array {
            if ($key === null) {
                return null;
            }

            return ['key' => $key, 'name' => EventCatalog::get($key)['name'] ?? 'Event'];
        };

        return match ($album) {
            'pets', 'mounts' => Item::where('type', 'egg')->where('active', true)->orderBy('id')->get()
                ->map(fn (Item $i) => ['key' => $i->key, 'name' => $i->name, 'icon' => $i->icon, 'event' => $event($i->event_key)])
                ->values()->all(),
            'monsters' => collect(QuestService::catalog())->where('type', 'boss')
                ->map(fn (array $q) => ['key' => $q['key'], 'name' => $q['name'], 'icon' => $q['icon'] ?? '👾', 'event' => $event($q['event_key'] ?? null)])
                ->values()->all(),
            'furniture' => Item::where('type', 'furniture')->where('active', true)->orderBy('id')->get()
                ->map(fn (Item $i) => ['key' => $i->key, 'name' => $i->name, 'icon' => $i->icon, 'event' => $event($i->event_key)])
                ->values()->all(),
            'recipes' => (function () use ($event) {
                $recipes = Catalog::recipes();
                $outputs = Item::whereIn('key', collect($recipes)->pluck('output')->unique()->all())->get()->keyBy('key');

                return collect($recipes)->map(function (array $r) use ($outputs, $event) {
                    $item = $outputs->get($r['output']);
                    $eventKey = $item?->event_key ?? (($r['unlock'] ?? 'level') === 'event' ? 'event' : null);

                    return ['key' => $r['key'], 'name' => $item?->name ?? $r['key'], 'icon' => $item?->icon ?? '📜', 'event' => $eventKey === 'event' ? ['key' => 'event', 'name' => 'Event'] : $event($eventKey)];
                })->values()->all();
            })(),
            'badges' => collect(AchievementService::catalog())
                ->flatMap(fn (array $a) => collect($a['tiers'])->keys()->map(fn (int $i) => [
                    'key' => "{$a['key']}:".($i + 1),
                    'name' => AchievementService::title($a['key'], $i + 1),
                    'icon' => '🏅',
                    'event' => null,
                ]))
                ->values()->all(),
            default => [],
        };
    }

    /**
     * Was die Person gerade besitzt bzw. erreicht hat (Schlüssel der Einträge).
     *
     * @return list<string>
     */
    public static function owned(User $user, string $album): array
    {
        return array_values(array_unique(match ($album) {
            'pets' => UserPet::join('items', 'items.id', '=', 'user_pets.egg_item_id')->where('user_pets.user_id', $user->id)->pluck('items.key')->all(),
            'mounts' => UserPet::join('items', 'items.id', '=', 'user_pets.egg_item_id')->where('user_pets.user_id', $user->id)
                ->where('user_pets.is_mount', true)->pluck('items.key')->all(),
            'monsters' => Quest::where('status', 'completed')->where('type', 'boss')
                ->where(function ($q) use ($user) {
                    $q->where(fn ($q) => $q->where('scope', 'solo')->where('user_id', $user->id));
                    if ($user->family_id && $user->family_role !== FamilyRole::Guest) {
                        $q->orWhere(fn ($q) => $q->where('scope', 'family')->where('family_id', $user->family_id));
                    }
                })->pluck('quest_key')->all(),
            'furniture' => [
                ...UserItem::join('items', 'items.id', '=', 'user_items.item_id')->where('user_items.user_id', $user->id)
                    ->where('items.type', 'furniture')->where('user_items.quantity', '>', 0)->pluck('items.key')->all(),
                ...PlacedItem::join('items', 'items.id', '=', 'placed_items.item_id')->where('placed_items.placed_by', $user->id)->pluck('items.key')->all(),
            ],
            'recipes' => (function () use ($user) {
                $level = (int) (UserCrafting::find($user->id)?->level ?? 1);
                $learned = UserRecipe::where('user_id', $user->id)->pluck('recipe_key')->all();

                return collect(Catalog::recipes())
                    ->filter(fn (array $r) => in_array($r['key'], $learned, true)
                        || (($r['unlock'] ?? 'level') === 'level' && $level >= ($r['level'] ?? 1)))
                    ->keys()->all();
            })(),
            'badges' => UserAchievement::where('user_id', $user->id)->get(['achievement_key', 'tier'])
                ->map(fn (UserAchievement $a) => "{$a->achievement_key}:{$a->tier}")->all(),
            default => [],
        }));
    }

    public static function percent(int $collected, int $total): int
    {
        return $total > 0 ? (int) floor(100 * $collected / $total) : 0;
    }

    /**
     * Alben auffrischen: Neues ins Album übernehmen, erreichte Stufen genau einmal belohnen.
     *
     * @return list<array{album: string, step: int, gold: float, item: ?array}> neu ausgezahlte Belohnungen
     */
    public function sync(User $user): array
    {
        $cfg = LongTermCatalog::all()['albums'];
        $paid = [];

        foreach (LongTermCatalog::albums() as $key => $album) {
            $entries = collect(self::entries($key))->pluck('key')->all();
            $owned = self::owned($user, $key);

            $paid = [...$paid, ...DB::transaction(function () use ($user, $key, $album, $entries, $owned, $cfg) {
                $row = AlbumProgress::lockForUpdate()->firstOrNew(['user_id' => $user->id, 'album' => $key]);
                // Nur Einträge, die es im Katalog (noch) gibt, zählen
                $collected = array_values(array_intersect($entries, array_unique([...($row->collected ?? []), ...$owned])));
                sort($collected);
                $percent = self::percent(count($collected), count($entries));
                $rewarded = $row->rewarded ?? [];
                $paid = [];

                foreach ($cfg['steps'] as $step) {
                    if ($percent < $step || in_array($step, $rewarded, true)) {
                        continue;
                    }
                    $rewarded[] = $step;
                    $reward = $cfg['step_rewards'][(string) $step] ?? [];
                    if ($step === 100 && isset($album['final_item'])) {
                        $reward['item'] = $album['final_item'];
                    }
                    $given = LongTermRewards::grant($user, $reward);
                    $this->badges->award($user, "album:{$key}:{$step}", 'album', "{$album['name']} {$step} %", $album['icon'],
                        ['album' => $key, 'step' => $step],
                        $step === 100 ? "{$user->name} hat das Album „{$album['name']}“ komplett!" : null);
                    $paid[] = ['album' => $key, 'step' => $step, ...$given];
                }

                $row->fill(['collected' => $collected, 'percent' => $percent, 'rewarded' => $rewarded]);
                if (! $row->exists || $row->isDirty()) {
                    $row->save();
                }

                return $paid;
            })];
        }

        return $paid;
    }

    /** Alben für die Anzeige (vorher sync aufrufen). */
    public function present(User $user): array
    {
        $cfg = LongTermCatalog::all()['albums'];
        $rows = AlbumProgress::where('user_id', $user->id)->get()->keyBy('album');
        $items = Item::whereIn('key', [...collect($cfg['step_rewards'])->pluck('item')->filter()->all(), ...collect(LongTermCatalog::albums())->pluck('final_item')->filter()->all()])
            ->get()->keyBy('key');

        return collect(LongTermCatalog::albums())->map(function (array $album, string $key) use ($rows, $cfg, $items) {
            $row = $rows->get($key);
            $collected = $row?->collected ?? [];
            $entries = collect(self::entries($key))->map(fn (array $e) => [...$e, 'collected' => in_array($e['key'], $collected, true)]);

            return [
                'key' => $key,
                'name' => $album['name'],
                'icon' => $album['icon'],
                'description' => $album['description'],
                'total' => $entries->count(),
                'collected' => $entries->where('collected', true)->count(),
                'percent' => self::percent($entries->where('collected', true)->count(), $entries->count()),
                'event_open' => $entries->where('collected', false)->whereNotNull('event')->count(),
                'steps' => collect($cfg['steps'])->map(function (int $step) use ($row, $cfg, $album, $items) {
                    $reward = $cfg['step_rewards'][(string) $step] ?? [];
                    $itemKey = $step === 100 ? ($album['final_item'] ?? $reward['item'] ?? null) : ($reward['item'] ?? null);

                    return [
                        'step' => $step,
                        'reached' => in_array($step, $row?->rewarded ?? [], true),
                        'gold' => (float) ($reward['gold'] ?? 0),
                        'item' => $itemKey ? $items->get($itemKey)?->only(['key', 'name', 'icon', 'type']) : null,
                    ];
                })->all(),
                'entries' => $entries->values()->all(),
            ];
        })->values()->all();
    }

    /** Ungesehene Album-Belohnungen (für den Hinweis im Heldenpanel). */
    public static function unseenRewards(User $user): int
    {
        return UserBadge::where('user_id', $user->id)->where('kind', 'album')->whereNull('seen_at')->count();
    }

    public static function markRewardsSeen(User $user): void
    {
        UserBadge::where('user_id', $user->id)->where('kind', 'album')->whereNull('seen_at')->update(['seen_at' => now()]);
    }
}
