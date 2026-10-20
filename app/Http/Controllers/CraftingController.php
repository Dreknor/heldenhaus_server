<?php

namespace App\Http\Controllers;

use App\Domain\Crafting\Catalog;
use App\Domain\Crafting\CraftingService;
use App\Domain\Crafting\StorageService;
use App\Domain\Crafting\TradeService;
use App\Domain\Gameplay\GameException;
use App\Models\CraftJob;
use App\Models\FamilyAlliance;
use App\Models\Item;
use App\Models\TradeOffer;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Crafting, Familienlager, Schenken und Tausch (Konzept §2.5, API §5.4). */
class CraftingController extends Controller
{
    public function __construct(
        private readonly CraftingService $crafting,
        private readonly StorageService $storage,
        private readonly TradeService $trades,
    ) {}

    public function recipes(Request $request): JsonResponse
    {
        return response()->json(CraftingService::present($request->user()));
    }

    public function craft(Request $request): JsonResponse
    {
        $data = $request->validate(['recipe' => ['required', 'string', Rule::in(array_keys(Catalog::recipes()))]]);
        $job = $this->crafting->start($request->user(), $data['recipe']);

        return response()->json(['job_id' => $job->id, 'quality' => $job->quality, 'quantity' => $job->quantity, 'finishes_at' => $job->finishes_at->toIso8601String()], 201);
    }

    public function collect(Request $request, CraftJob $job): JsonResponse
    {
        $result = $this->crafting->collect($request->user(), $job);

        return response()->json([
            'item' => ['key' => $result['job']->item->key, 'name' => $result['job']->item->name, 'icon' => $result['job']->item->icon],
            'quantity' => $result['job']->quantity,
            'quality' => $result['job']->quality,
            'level_up' => $result['level_up'],
            'crafting' => CraftingService::crafting($request->user())->only('level', 'xp'),
        ]);
    }

    public function learn(Request $request): JsonResponse
    {
        $data = $request->validate(['recipe' => ['required', 'string']]);
        $this->crafting->learn($request->user(), $data['recipe']);

        return response()->json(['ok' => true], 201);
    }

    // ---------- Familienlager und Schenken ----------

    public function storage(Request $request): JsonResponse
    {
        return response()->json(['storage' => StorageService::present($request->user()->family_id ?? 0)]);
    }

    public function deposit(Request $request): JsonResponse
    {
        [$item, $quantity] = $this->itemAndQuantity($request);
        $this->storage->deposit($request->user(), $item, $quantity);

        return response()->json(['storage' => StorageService::present($request->user()->family_id)]);
    }

    public function withdraw(Request $request): JsonResponse
    {
        [$item, $quantity] = $this->itemAndQuantity($request);
        $this->storage->withdraw($request->user(), $item, $quantity);

        return response()->json(['storage' => StorageService::present($request->user()->family_id)]);
    }

    public function gift(Request $request): JsonResponse
    {
        [$item, $quantity] = $this->itemAndQuantity($request);
        $data = $request->validate(['to' => ['required', 'integer'], 'master' => ['sometimes', 'boolean']]);
        $to = User::where('family_id', $request->user()->family_id)->findOrFail($data['to']);
        $this->storage->gift($request->user(), $to, $item, $quantity, (bool) ($data['master'] ?? false));

        return response()->json(['ok' => true], 201);
    }

    // ---------- Tausch ----------

    public function trades(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(['trades' => $this->trades->visible($user)->map(fn (TradeOffer $t) => TradeService::present($t, $user))->values()]);
    }

    public function storeTrade(Request $request): JsonResponse
    {
        $data = $request->validate([
            'alliance_id' => ['required', 'integer'],
            'offer' => ['required', 'array', 'min:1', 'max:'.TradeService::MAX_KINDS],
            'offer.*' => ['integer', 'min:1', 'max:999'],
            'want' => ['required', 'array', 'min:1', 'max:'.TradeService::MAX_KINDS],
            'want.*' => ['integer', 'min:1', 'max:999'],
        ]);
        $alliance = FamilyAlliance::findOrFail($data['alliance_id']);
        $trade = $this->trades->create($request->user(), $alliance, $data['offer'], $data['want']);

        return response()->json(TradeService::present($trade->load('user'), $request->user()), 201);
    }

    public function tradeAction(Request $request, TradeOffer $trade, string $action): JsonResponse
    {
        $user = $request->user();
        $trade = match ($action) {
            'accept' => $this->trades->accept($user, $trade),
            'approve' => $this->trades->approve($user, $trade),
            'reject' => $this->trades->reject($user, $trade),
            'cancel' => $this->trades->cancel($user, $trade),
        };

        return response()->json(TradeService::present($trade->refresh()->load('user', 'acceptor'), $user));
    }

    /** @return array{0: Item, 1: int} */
    private function itemAndQuantity(Request $request): array
    {
        $data = $request->validate(['item_id' => ['required', 'integer'], 'quantity' => ['required', 'integer', 'min:1', 'max:999']]);

        return [Item::find($data['item_id']) ?? throw new GameException('Diesen Gegenstand gibt es nicht.'), (int) $data['quantity']];
    }
}
