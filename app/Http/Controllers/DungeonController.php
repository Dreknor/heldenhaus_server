<?php

namespace App\Http\Controllers;

use App\Domain\Achievements\AchievementService;
use App\Domain\Art\MonsterSvg;
use App\Domain\Dungeons\DungeonCatalog;
use App\Domain\Dungeons\DungeonService;
use App\Models\Dungeon;
use App\Models\DungeonPosition;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Dungeon-Quests (Konzept §2.7, API §5.4): Absichten schicken, der Server entscheidet. */
class DungeonController extends Controller
{
    public function __construct(private readonly DungeonService $dungeons, private readonly AchievementService $achievements) {}

    public function current(Request $request): JsonResponse
    {
        return response()->json(DungeonService::present($this->dungeons->current($request->user()), $request->user()));
    }

    public function solo(Request $request): JsonResponse
    {
        return response()->json(DungeonService::present($this->dungeons->solo($request->user()), $request->user()));
    }

    public function show(Request $request, Dungeon $dungeon): JsonResponse
    {
        abort_unless($dungeon->family_id === $request->user()->family_id && ($dungeon->kind === 'family' || $dungeon->user_id === $request->user()->id), 404);

        return response()->json(DungeonService::present($dungeon, $request->user()));
    }

    public function act(Request $request, Dungeon $dungeon, string $action): JsonResponse
    {
        $input = $request->validate([
            'client_uuid' => ['nullable', 'uuid'],
            'to' => ['sometimes', 'integer', 'min:0', 'max:63'],
            'move' => ['sometimes', 'in:attack,skill,potion'],
            'answer' => ['sometimes', 'string', 'max:20'],
            'buy' => ['sometimes', 'string', 'max:40'],
            'item_id' => ['sometimes', 'integer'],
        ]);
        $outcome = $this->dungeons->act($request->user(), $dungeon, $action, $input, $input['client_uuid'] ?? null);

        // Erfolge nach dem Zug (außerhalb der Zug-Transaktion)
        $dungeon->refresh();
        $users = $dungeon->status === 'completed'
            ? User::whereIn('id', DungeonPosition::where('dungeon_id', $dungeon->id)->pluck('user_id'))->get()
            : collect([$request->user()]);
        $users->each(fn (User $u) => $this->achievements->evaluate($u, ['dungeons_cleared', 'monsters_defeated']));

        return response()->json([
            'result' => $outcome['result'],
            'replayed' => $outcome['replayed'],
            'dungeon' => DungeonService::present($dungeon->refresh(), $request->user()),
        ]);
    }

    /** Monster-Grafik aus dem Baukasten (dungeons.json „art“, sonst Quest-Boss), als SVG. */
    public function monsterArt(string $key): Response
    {
        $art = DungeonCatalog::monsterArt($key) ?? abort(404);

        return response(MonsterSvg::render($art), 200, ['Content-Type' => 'image/svg+xml', 'Cache-Control' => 'public, max-age=86400']);
    }

    public function log(Request $request, Dungeon $dungeon): JsonResponse
    {
        abort_unless($dungeon->family_id === $request->user()->family_id && ($dungeon->kind === 'family' || $dungeon->user_id === $request->user()->id), 404);

        return response()->json(['log' => DungeonService::log($dungeon, $request->integer('since'))]);
    }
}
