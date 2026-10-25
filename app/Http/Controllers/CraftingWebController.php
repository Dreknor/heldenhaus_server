<?php

namespace App\Http\Controllers;

use App\Domain\Crafting\Catalog;
use App\Domain\Crafting\CraftingService;
use App\Domain\Crafting\StorageService;
use App\Domain\Crafting\TradeService;
use App\Domain\Gameplay\GameException;
use App\Domain\Housing\HomeService;
use App\Models\CraftJob;
use App\Models\FamilyAlliance;
use App\Models\Item;
use App\Models\TradeOffer;
use App\Models\User;
use App\Models\UserItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Web UI for crafting, family storage, gifting, and alliance trades. */
class CraftingWebController extends Controller
{
    public function __construct(
        private readonly CraftingService $crafting,
        private readonly StorageService $storage,
        private readonly TradeService $trades,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $familyId = $user->family_id;
        $allianceIds = FamilyAlliance::active()->of($familyId)->pluck('id');

        return Inertia::render('Crafting', [
            'crafting' => CraftingService::present($user),
            'storage' => StorageService::present($familyId),
            'libraryAvailable' => HomeService::hasRoom(HomeService::home($familyId), 'library'),
            'members' => $user->family->members()->where('id', '!=', $user->id)->orderBy('name')->get(['id', 'name']),
            'items' => UserItem::with('item')->where('user_id', $user->id)->where('quantity', '>', 0)->get()
                ->filter(fn (UserItem $stock) => $stock->item->active && $stock->item->type !== 'equipment')
                ->sortBy(fn (UserItem $stock) => [$stock->item->type, $stock->item->name])
                ->map(fn (UserItem $stock) => [
                    'id' => $stock->item_id,
                    'key' => $stock->item->key,
                    'name' => $stock->item->name,
                    'icon' => $stock->item->icon,
                    'type' => $stock->item->type,
                    'quantity' => $stock->quantity - $stock->master_quantity,
                    'master_quantity' => $stock->master_quantity,
                ])->values(),
            'alliances' => FamilyAlliance::with('family:id,name', 'partnerFamily:id,name')
                ->whereIn('id', $allianceIds)->get()
                ->map(fn (FamilyAlliance $alliance) => [
                    'id' => $alliance->id,
                    'name' => ($alliance->family_id === $familyId ? $alliance->partnerFamily : $alliance->family)?->name ?? 'Bündnisfamilie',
                ])->values(),
            'trades' => $this->trades->visible($user)
                ->map(fn (TradeOffer $trade) => TradeService::present($trade, $user))->values(),
        ]);
    }

    public function craft(Request $request): RedirectResponse
    {
        $recipe = $request->validate(['recipe' => ['required', 'string', Rule::in(array_keys(Catalog::recipes()))]])['recipe'];
        $job = $this->crafting->start($request->user(), $recipe);

        return back()->with('success', "Herstellung gestartet – Auftrag #{$job->id}.");
    }

    public function collect(Request $request, CraftJob $job): RedirectResponse
    {
        $result = $this->crafting->collect($request->user(), $job);
        $item = $result['job']->item;

        return back()->with('success', "{$result['job']->quantity}× {$item->name} abgeholt."
            .($result['level_up'] ? ' Handwerksstufe gestiegen!' : ''));
    }

    public function learn(Request $request): RedirectResponse
    {
        $recipe = $request->validate(['recipe' => ['required', 'string']])['recipe'];
        $this->crafting->learn($request->user(), $recipe);

        return back()->with('success', 'Rezept gelernt.');
    }

    public function deposit(Request $request): RedirectResponse
    {
        [$item, $quantity] = $this->itemAndQuantity($request);
        $this->storage->deposit($request->user(), $item, $quantity);

        return back()->with('success', "{$quantity}× {$item->name} ins Familienlager gelegt.");
    }

    public function withdraw(Request $request): RedirectResponse
    {
        [$item, $quantity] = $this->itemAndQuantity($request);
        $this->storage->withdraw($request->user(), $item, $quantity);

        return back()->with('success', "{$quantity}× {$item->name} aus dem Familienlager genommen.");
    }

    public function gift(Request $request): RedirectResponse
    {
        [$item, $quantity] = $this->itemAndQuantity($request);
        $data = $request->validate([
            'to' => ['required', 'integer'],
            'master' => ['sometimes', 'boolean'],
        ]);
        $recipient = User::where('family_id', $request->user()->family_id)->findOrFail($data['to']);
        $this->storage->gift($request->user(), $recipient, $item, $quantity, (bool) ($data['master'] ?? false));

        return back()->with('success', "{$quantity}× {$item->name} an {$recipient->name} verschenkt.");
    }

    public function storeTrade(Request $request): RedirectResponse
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

        return back()->with('success', $trade->status === 'awaiting_parent'
            ? 'Angebot erstellt – es wartet auf die Zustimmung der Eltern.'
            : 'Tauschangebot veröffentlicht.');
    }

    public function tradeAction(Request $request, TradeOffer $trade, string $action): RedirectResponse
    {
        abort_unless(in_array($action, ['accept', 'approve', 'reject', 'cancel'], true), 404);

        match ($action) {
            'accept' => $this->trades->accept($request->user(), $trade),
            'approve' => $this->trades->approve($request->user(), $trade),
            'reject' => $this->trades->reject($request->user(), $trade),
            'cancel' => $this->trades->cancel($request->user(), $trade),
        };

        return back()->with('success', match ($action) {
            'accept' => 'Tausch angenommen.',
            'approve' => 'Tausch genehmigt.',
            'reject' => 'Tausch abgelehnt.',
            'cancel' => 'Tauschangebot zurückgezogen.',
        });
    }

    /** @return array{0: Item, 1: int} */
    private function itemAndQuantity(Request $request): array
    {
        $data = $request->validate([
            'item_id' => ['required', 'integer'],
            'quantity' => ['required', 'integer', 'min:1', 'max:999'],
        ]);
        $item = Item::find($data['item_id']) ?? throw new GameException('Diesen Gegenstand gibt es nicht.');

        return [$item, (int) $data['quantity']];
    }
}
