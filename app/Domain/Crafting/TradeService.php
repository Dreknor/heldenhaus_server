<?php

namespace App\Domain\Crafting;

use App\Domain\Gameplay\GameException;
use App\Domain\Inventory\Stock;
use App\Domain\Notifications\PushNotifier;
use App\Domain\Social\FeedService;
use App\Enums\AgeGroup;
use App\Models\FamilyAlliance;
use App\Models\Item;
use App\Models\TradeOffer;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Tausch mit Bündnis-Familien (Konzept §2.5): nur Gegenstände gegen Gegenstände, kein Gold und keine Sterne.
 * Angebotene Dinge liegen bis zum Abschluss „in der Truhe“ (werden beim Erstellen bzw. Annehmen abgezogen).
 * Bei Kindern unter 13 stimmen die Eltern der eigenen Familie zu – beim Anbieten wie beim Annehmen.
 *
 * Status: awaiting_parent → open → (awaiting_partner_parent →) done; außerdem cancelled und rejected.
 */
class TradeService
{
    public const MAX_KINDS = 5;

    public function __construct(private readonly FeedService $feed, private readonly PushNotifier $push) {}

    public function create(User $user, FamilyAlliance $alliance, array $offer, array $want): TradeOffer
    {
        if ($alliance->status !== 'active' || ! $alliance->involves($user->family_id)) {
            throw new GameException('Tauschen geht nur mit Familien, mit denen ihr verbündet seid.');
        }
        $offer = $this->normalize($offer);
        $want = $this->normalize($want);

        $trade = DB::transaction(function () use ($user, $alliance, $offer, $want) {
            $this->escrow($user, $offer);

            return TradeOffer::create([
                'user_id' => $user->id,
                'family_id' => $user->family_id,
                'alliance_id' => $alliance->id,
                'offer' => $offer,
                'want' => $want,
                'status' => self::needsParent($user) ? 'awaiting_parent' : 'open',
            ]);
        });

        if ($trade->status === 'awaiting_parent') {
            $this->push->notify(PushNotifier::parentsOf($user->family_id), 'trade');
        }

        return $trade;
    }

    public function accept(User $user, TradeOffer $trade): TradeOffer
    {
        $trade = DB::transaction(function () use ($user, $trade) {
            $trade = TradeOffer::with('alliance')->lockForUpdate()->findOrFail($trade->id);
            if ($trade->status !== 'open') {
                throw new GameException('Dieses Angebot ist nicht mehr offen.');
            }
            if ($trade->family_id === $user->family_id || $trade->alliance->status !== 'active' || ! $trade->alliance->involves($user->family_id)) {
                throw new GameException('Dieses Angebot ist nicht für dich.');
            }

            $this->escrow($user, $trade->want);
            $trade->accepted_by = $user->id;

            if (self::needsParent($user)) {
                $trade->update(['status' => 'awaiting_partner_parent']);
            } else {
                $this->complete($trade);
            }

            return $trade;
        });

        $this->push->notify($trade->status === 'done' ? $trade->user : PushNotifier::parentsOf($user->family_id), 'trade');

        return $trade;
    }

    /** Elternteil der Familie, die gerade zustimmen muss. */
    public function approve(User $parent, TradeOffer $trade): TradeOffer
    {
        $trade = DB::transaction(function () use ($parent, $trade) {
            $trade = TradeOffer::with('acceptor')->lockForUpdate()->findOrFail($trade->id);
            $this->ensureDecider($parent, $trade);

            if ($trade->status === 'awaiting_parent') {
                $trade->update(['status' => 'open', 'approved_by' => $parent->id]);
            } else {
                $this->complete($trade);
            }

            return $trade;
        });

        $this->push->notify($trade->status === 'done' ? collect([$trade->user, $trade->acceptor]) : $trade->user, 'trade');

        return $trade;
    }

    /** Ablehnen: beim Anbieten ist das Angebot vom Tisch, beim Annehmen ist es wieder offen für andere. */
    public function reject(User $parent, TradeOffer $trade): TradeOffer
    {
        return DB::transaction(function () use ($parent, $trade) {
            $trade = TradeOffer::with('acceptor')->lockForUpdate()->findOrFail($trade->id);
            $this->ensureDecider($parent, $trade);

            if ($trade->status === 'awaiting_parent') {
                $this->refund($trade->user_id, $trade->offer);
                $trade->update(['status' => 'rejected', 'approved_by' => $parent->id]);
            } else {
                $this->refund($trade->accepted_by, $trade->want);
                $trade->update(['status' => 'open', 'accepted_by' => null]);
            }

            return $trade;
        });
    }

    /** Zurückziehen – durch die anbietende Person oder ihre Eltern, solange niemand angenommen hat. */
    public function cancel(User $user, TradeOffer $trade): TradeOffer
    {
        return DB::transaction(function () use ($user, $trade) {
            $trade = TradeOffer::lockForUpdate()->findOrFail($trade->id);
            if ($trade->user_id !== $user->id && ! ($user->isParent() && $user->family_id === $trade->family_id)) {
                throw new GameException('Das ist nicht dein Angebot.');
            }
            if (! in_array($trade->status, ['awaiting_parent', 'open'], true)) {
                throw new GameException('Dieses Angebot lässt sich nicht mehr zurückziehen.');
            }
            $this->refund($trade->user_id, $trade->offer);
            $trade->update(['status' => 'cancelled']);

            return $trade;
        });
    }

    /** Eigene Angebote und offene Angebote der Bündnis-Familien. */
    public function visible(User $user): Collection
    {
        $allied = FamilyAlliance::active()->of($user->family_id)->pluck('id');

        return TradeOffer::with('user', 'acceptor')
            ->where(fn ($q) => $q->where('family_id', $user->family_id)->whereIn('status', ['awaiting_parent', 'open', 'awaiting_partner_parent'])
                ->orWhere(fn ($q) => $q->whereIn('alliance_id', $allied)->where('family_id', '!=', $user->family_id)
                    ->where(fn ($q) => $q->where('status', 'open')->orWhere(fn ($q) => $q->where('status', 'awaiting_partner_parent')
                        ->whereIn('accepted_by', User::where('family_id', $user->family_id)->select('id'))))))
            ->latest('id')->limit(50)->get();
    }

    public static function present(TradeOffer $trade, User $viewer): array
    {
        $ids = array_merge(array_keys($trade->offer), array_keys($trade->want));
        $items = Item::whereIn('id', $ids)->get()->keyBy('id');
        $list = fn (array $amounts) => collect($amounts)->map(fn ($q, $id) => [
            'item_id' => (int) $id, 'key' => $items[$id]->key ?? null, 'name' => $items[$id]->name ?? '?', 'icon' => $items[$id]->icon ?? '📦', 'quantity' => $q,
        ])->values();
        $own = $trade->family_id === $viewer->family_id;

        return [
            'id' => $trade->id,
            'own' => $own,
            'from' => ['id' => $trade->user_id, 'name' => $trade->user?->name],
            'accepted_by' => $trade->acceptor ? ['id' => $trade->acceptor->id, 'name' => $trade->acceptor->name] : null,
            'offer' => $list($trade->offer),
            'want' => $list($trade->want),
            'status' => $trade->status,
            'can_approve' => $viewer->isParent() && (
                ($trade->status === 'awaiting_parent' && $own)
                || ($trade->status === 'awaiting_partner_parent' && $trade->acceptor?->family_id === $viewer->family_id)
            ),
            'created_at' => $trade->created_at->toIso8601String(),
        ];
    }

    public static function needsParent(User $user): bool
    {
        return ! $user->isParent() && $user->ageGroup() === AgeGroup::Child;
    }

    private function complete(TradeOffer $trade): void
    {
        $this->refund($trade->accepted_by, $trade->offer);  // Angebot geht an die annehmende Person …
        $this->refund($trade->user_id, $trade->want);       // … und das Gewünschte an die anbietende
        $trade->update(['status' => 'done', 'completed_at' => now()]);

        $trade->loadMissing('user', 'acceptor');
        $this->feed->post($trade->family_id, $trade->user, 'trade', '🤝', "{$trade->user->name} hat mit {$trade->acceptor->name} getauscht.");
        $this->feed->post($trade->acceptor->family_id, $trade->acceptor, 'trade', '🤝', "{$trade->acceptor->name} hat mit {$trade->user->name} getauscht.");
    }

    private function ensureDecider(User $parent, TradeOffer $trade): void
    {
        $family = match ($trade->status) {
            'awaiting_parent' => $trade->family_id,
            'awaiting_partner_parent' => $trade->acceptor?->family_id,
            default => throw new GameException('Hier ist keine Zustimmung nötig.'),
        };
        if (! $parent->isParent() || $parent->family_id !== $family) {
            throw new GameException('Zustimmen können nur die Eltern.');
        }
    }

    /** @param  array<int, int>  $amounts */
    private function escrow(User $user, array $amounts): void
    {
        $items = Item::whereIn('id', array_keys($amounts))->get()->keyBy('id');
        foreach ($amounts as $id => $quantity) {
            Stock::take($user->id, $items[$id], $quantity);
        }
    }

    private function refund(?int $userId, array $amounts): void
    {
        foreach ($userId ? $amounts : [] as $id => $quantity) {
            Stock::add($userId, (int) $id, (int) $quantity);
        }
    }

    /** {"item_id": menge} prüfen: nur echte Gegenstände, keine Ausrüstung, 1–5 Sorten. */
    private function normalize(array $amounts): array
    {
        $amounts = collect($amounts)->mapWithKeys(fn ($q, $id) => [(int) $id => (int) $q])->filter(fn ($q) => $q > 0);
        if ($amounts->isEmpty() || $amounts->count() > self::MAX_KINDS) {
            throw new GameException('Ein Tausch braucht 1 bis '.self::MAX_KINDS.' Sorten auf jeder Seite.');
        }
        $items = Item::whereIn('id', $amounts->keys())->where('active', true)->get();
        if ($items->count() !== $amounts->count()) {
            throw new GameException('Einen dieser Gegenstände gibt es nicht.');
        }
        if ($items->contains(fn (Item $i) => $i->type === 'equipment')) {
            throw new GameException('Ausrüstung kann man nicht tauschen.');
        }

        return $amounts->all();
    }
}
