<?php

namespace App\Http\Controllers;

use App\Domain\Achievements\AchievementService;
use App\Domain\Dungeons\DungeonCatalog;
use App\Domain\Dungeons\DungeonService;
use App\Domain\Features\Features;
use App\Domain\Gameplay\GameException;
use App\Domain\Inventory\Stock;
use App\Models\Dungeon;
use App\Models\DungeonPosition;
use App\Models\Item;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Browser interface for the same server-authoritative dungeon rules used by the app API. */
class DungeonWebController extends Controller
{
    public function __construct(
        private readonly DungeonService $dungeons,
        private readonly AchievementService $achievements,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $timezone = $user->family?->timezone ?? 'Europe/Berlin';
        $week = CarbonImmutable::now($timezone)->startOfWeek();
        $familyUnlocked = Features::has($user, 'family_dungeon');
        $soloUnlocked = Features::has($user, 'dungeons');

        $familyDungeon = Dungeon::where('family_id', $user->family_id)
            ->where('kind', 'family')
            ->whereDate('starts_on', $week->toDateString())
            ->first();
        if ($familyDungeon === null && $familyUnlocked) {
            $familyDungeon = $this->dungeons->current($user);
        }

        $soloDungeon = Dungeon::where('user_id', $user->id)
            ->where('kind', 'solo')
            ->whereDate('starts_on', $user->gameDate()->toDateString())
            ->first();

        $familyStats = $user->stats;
        $potion = Item::where('key', 'potion_health')->first();
        $key = Item::where('key', 'dungeon_key')->first();
        $healingPotions = Item::where('type', 'potion')->get()
            ->filter(fn (Item $item) => ! empty($item->stats['heal']))
            ->values();
        $healingPotionCounts = Stock::counts($user->id, $healingPotions->pluck('key')->all());

        return Inertia::render('Dungeons', [
            'familyDungeon' => $familyDungeon ? DungeonService::present($familyDungeon, $user) : null,
            'soloDungeon' => $soloDungeon ? DungeonService::present($soloDungeon, $user) : null,
            'familyUnlocked' => $familyUnlocked,
            'soloUnlocked' => $soloUnlocked,
            'familyUnlockLevel' => Features::levelFor('family_dungeon', $user),
            'soloUnlockLevel' => Features::levelFor('dungeons', $user),
            'stats' => [
                'mp' => $familyStats?->mp ?? 0,
                'class' => DungeonService::classOf($user),
            ],
            'currentUserId' => $user->id,
            'merchantOffers' => collect(DungeonCatalog::all()['merchant'])->map(function (int $price, string $key) {
                $item = Item::where('key', $key)->first();

                return ['key' => $key, 'name' => $item?->name ?? str_replace('_', ' ', $key), 'icon' => $item?->icon ?? '📦', 'price' => $price];
            })->values(),
            'inventory' => [
                'potions' => Stock::counts($user->id, ['potion_health'])['potion_health'],
                'keys' => Stock::counts($user->id, ['dungeon_key'])['dungeon_key'],
                'potionItemId' => $potion?->id,
                'keyItemId' => $key?->id,
                'healingPotions' => $healingPotions->map(fn (Item $item) => [
                    'id' => $item->id,
                    'name' => $item->name,
                    'icon' => $item->icon,
                    'count' => $healingPotionCounts[$item->key] ?? 0,
                ])->all(),
            ],
            'logs' => $familyDungeon
                ? DungeonService::log($familyDungeon, max(0, (int) ($familyDungeon->actions()->max('id') ?? 0) - 20))
                : [],
            'soloLogs' => $soloDungeon
                ? DungeonService::log($soloDungeon, max(0, (int) ($soloDungeon->actions()->max('id') ?? 0) - 20))
                : [],
            'selectedTab' => $request->session()->get('dungeon_tab', $request->query('tab', 'family')),
            'lastResult' => $request->session()->get('dungeon_result'),
        ]);
    }

    public function solo(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! Features::has($user, 'dungeons')) {
            return back()->withErrors(['dungeon' => 'Das Solo-Gewölbe ist noch nicht freigeschaltet.']);
        }

        try {
            $this->dungeons->solo($user);
        } catch (GameException $exception) {
            return back()->withErrors(['dungeon' => $exception->getMessage()]);
        }

        return to_route('dungeons.index')->with('dungeon_tab', 'solo')->with('success', 'Dein Solo-Gewölbe ist bereit!');
    }

    public function act(Request $request, Dungeon $dungeon, string $action): RedirectResponse
    {
        $input = $request->validate([
            'client_uuid' => ['nullable', 'uuid'],
            'to' => ['sometimes', 'integer', 'min:0', 'max:63'],
            'move' => ['sometimes', 'in:attack,skill,potion'],
            'answer' => ['sometimes', 'string', 'max:20'],
            'buy' => ['sometimes', 'string', 'max:40'],
            'item_id' => ['sometimes', 'integer'],
        ]);

        $user = $request->user();
        $feature = $dungeon->kind === 'family' ? 'family_dungeon' : 'dungeons';
        if (! Features::has($user, $feature)) {
            return back()->withErrors(['dungeon' => 'Dieser Dungeon ist für dich noch nicht freigeschaltet.']);
        }

        try {
            $outcome = $this->dungeons->act($user, $dungeon, $action, $input, $input['client_uuid'] ?? null);
        } catch (GameException $exception) {
            return back()->withErrors(['dungeon' => $exception->getMessage()]);
        }

        Features::starterDone($user, $feature);
        $dungeon->refresh();
        $participants = $dungeon->status === 'completed'
            ? User::whereIn('id', DungeonPosition::where('dungeon_id', $dungeon->id)->pluck('user_id'))->get()
            : collect([$user]);
        $participants->each(fn (User $participant) => $this->achievements->evaluate($participant, ['dungeons_cleared', 'monsters_defeated']));

        return back()
            ->with('dungeon_tab', $dungeon->kind === 'solo' ? 'solo' : 'family')
            ->with('dungeon_result', $outcome['result']);
    }
}
