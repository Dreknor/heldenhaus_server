<?php

namespace App\Http\Presenters;

use App\Domain\Classes\ClassCatalog;
use App\Domain\Classes\ClassService;
use App\Domain\Gameplay\Attributes;
use App\Domain\Quests\QuestService;
use App\Models\ActiveBuff;
use App\Models\DayLog;
use App\Models\Item;
use App\Models\SkillCast;
use App\Models\TaskAssignment;
use App\Models\User;
use App\Models\UserEquipment;
use App\Models\UserItem;
use App\Models\UserPet;

/** Held, Klasse, Ausrüstung, Inventar, Haustiere und Boss – für Web und API. */
class CharacterSheet
{
    public static function hero(User $user): array
    {
        $stats = $user->stats()->first();
        $classes = app(ClassService::class);

        return [
            'stats' => GamePresenter::stats($stats),
            'points' => $stats->only(Attributes::KEYS),
            'attributes' => Attributes::for($stats),
            'hero_class' => ClassCatalog::get($stats->class_key),
            'class_changed_at' => $stats->class_changed_at?->toDateString(),
            'unlock_level' => ClassCatalog::unlockLevel($user),
            'classes' => collect(ClassCatalog::all())->map(fn ($class) => [
                ...$class,
                'choice' => $classes->canChoose($user, $class['key']),
            ])->values(),
            'skills_today' => SkillCast::where('user_id', $user->id)->where('created_at', '>=', now()->startOfDay())
                ->selectRaw('skill_key, count(*) as count')->groupBy('skill_key')->pluck('count', 'skill_key'),
            'second_chance_used' => SkillCast::where('user_id', $user->id)->where('skill_key', 'second_chance')
                ->where('created_at', '>=', now()->startOfWeek())->exists(),
            'second_chance_options' => $stats->class_key === 'healer' ? self::secondChanceOptions($user) : [],
            'buffs' => ActiveBuff::current()
                ->where(fn ($q) => $q->where('user_id', $user->id)->orWhere('family_id', $user->family_id))
                ->get()->unique('key')->map(fn (ActiveBuff $b) => [
                    'key' => $b->key,
                    'name' => ClassCatalog::skill($b->key)['name'] ?? $b->key,
                    'armed' => $b->armed,
                    'expires_at' => $b->expires_at->toIso8601String(),
                ])->values(),
            'family_members' => $user->family?->members()->orderBy('name')->get(['id', 'name', 'avatar_color']) ?? [],
            'equipment' => UserEquipment::with('item')->where('user_id', $user->id)->get()
                ->mapWithKeys(fn (UserEquipment $e) => [$e->slot => self::item($e->item)]),
            'slots' => Item::SLOTS,
        ];
    }

    public static function inventory(User $user): array
    {
        $equipped = UserEquipment::where('user_id', $user->id)->pluck('item_id')->all();

        return [
            'items' => UserItem::with('item')->where('user_id', $user->id)->where('quantity', '>', 0)->get()
                ->sortBy(fn (UserItem $u) => [$u->item->type, $u->item->name])
                ->map(fn (UserItem $u) => [...self::item($u->item), 'quantity' => $u->quantity, 'equipped' => in_array($u->item_id, $equipped, true)])
                ->values(),
            'shop' => Item::where('active', true)->whereNotNull('price_gold')->orderBy('type')->orderBy('price_gold')->get()
                ->map(fn (Item $i) => self::item($i))->values(),
            'pets' => UserPet::with('egg', 'potion')->where('user_id', $user->id)->orderBy('name')->get()
                ->map(fn (UserPet $p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'egg_icon' => $p->egg->icon,
                    'egg_key' => $p->egg->key,
                    'potion_key' => $p->potion->key,
                    'potion_icon' => $p->potion->icon,
                    'growth' => $p->growth,
                    'growth_max' => (int) config('game.pet_growth_mount'),
                    'is_mount' => $p->is_mount,
                ]),
            'types' => Item::TYPES,
            'rarities' => Item::RARITIES,
            'level' => $user->stats()->value('level'),
        ];
    }

    /** Laufende Familien- und Solo-Quest der Person. */
    public static function quests(User $user): array
    {
        $service = app(QuestService::class);

        return [
            'family' => QuestService::present($service->activeFamily($user->family_id)),
            'solo' => QuestService::present($service->activeSolo($user)),
        ];
    }

    /** Aussehen für den Avatar-Baukasten: Einstellungen, sichtbare Ausrüstung, Klassen-Abzeichen. */
    public static function appearance(User $user): array
    {
        return [
            'avatar' => $user->avatar,
            'seed' => $user->id,
            'equipment' => UserEquipment::with('item:id,key,rarity')->where('user_id', $user->id)->get()
                ->mapWithKeys(fn (UserEquipment $e) => [$e->slot => $e->item?->only(['key', 'rarity'])]),
            'class_icon' => ClassCatalog::get($user->stats?->class_key ?? $user->stats()->value('class_key'))['icon'] ?? null,
        ];
    }

    public static function item(?Item $item): ?array
    {
        return $item?->only(['id', 'key', 'name', 'description', 'type', 'slot', 'rarity', 'icon', 'stats', 'price_gold', 'min_level']);
    }

    /** Verpasste Dailies der Familie aus den letzten 24 Stunden (für „Zweite Chance“). */
    private static function secondChanceOptions(User $user): array
    {
        $memberIds = $user->family?->members()->pluck('id') ?? collect([$user->id]);

        return DayLog::whereIn('user_id', $memberIds)->where('created_at', '>=', now()->subDay())->get()
            ->flatMap(fn (DayLog $log) => collect($log->missed ?? [])->map(fn ($m) => [...$m, 'user_id' => $log->user_id]))
            ->map(function ($m) {
                $a = TaskAssignment::with('task', 'user')->find($m['assignment_id']);

                return $a ? ['assignment_id' => $a->id, 'title' => $a->task->title, 'user' => $a->user->name, 'damage' => $m['damage']] : null;
            })
            ->filter()
            ->values()
            ->all();
    }
}
