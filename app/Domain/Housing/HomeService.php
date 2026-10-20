<?php

namespace App\Domain\Housing;

use App\Domain\Crafting\StorageService;
use App\Domain\Gameplay\GameException;
use App\Domain\Inventory\Stock;
use App\Enums\FamilyRole;
use App\Models\GuestbookEntry;
use App\Models\Home;
use App\Models\Item;
use App\Models\PlacedItem;
use App\Models\Quest;
use App\Models\Room;
use App\Models\Task;
use App\Models\User;
use App\Models\UserAchievement;
use App\Models\UserItem;
use Illuminate\Support\Facades\DB;

/**
 * Familienhaus mit Gemeinschaftsräumen und einem eigenen Zimmer je Familienmitglied (Konzept §2.6).
 * Das Haus entsteht beim ersten Aufruf als Hütte mit Wohnraum und Werkbank.
 */
class HomeService
{
    public const ROTATIONS = [0, 90, 180, 270];

    public const LAYERS = ['floor', 'wall', 'ceiling'];

    public static function home(int $familyId): Home
    {
        $home = Home::firstOrCreate(['family_id' => $familyId], ['stage' => 'hut', 'stations' => ['workbench' => 1]]);

        if (! $home->rooms()->where('type', 'living')->exists()) {
            [$w, $h] = config('game.housing.room_size.hut');
            $home->rooms()->create(['type' => 'living', 'width' => $w, 'height' => $h]);
        }

        // Ein eigenes Zimmer für jedes Familienmitglied (Gäste ausgenommen), auch für später Hinzugekommene
        $withRoom = $home->rooms()->where('type', 'bedroom')->pluck('owner_user_id')->all();
        [$w, $h] = config('game.housing.bedroom_size');
        User::where('family_id', $familyId)->where('family_role', '!=', FamilyRole::Guest)->whereNotIn('id', $withRoom)
            ->get()->each(fn (User $u) => $home->rooms()->create(['type' => 'bedroom', 'owner_user_id' => $u->id, 'width' => $w, 'height' => $h]));

        return $home;
    }

    public static function hasRoom(Home $home, string $type): bool
    {
        return $home->rooms()->where('type', $type)->exists();
    }

    /** Eigenes Zimmer: nur die Person selbst und ihre Eltern. Gemeinschaftsräume: alle außer Gästen. */
    public static function canEdit(User $user, Room $room): bool
    {
        $room->loadMissing('home');
        if ($room->home->family_id !== $user->family_id || $user->family_role === FamilyRole::Guest) {
            return false;
        }

        return $room->owner_user_id === null || $room->owner_user_id === $user->id || $user->isParent();
    }

    /**
     * Ganzes Layout speichern (§5.4). Bestehende Möbel behalten ihre id; neue kommen aus dem Inventar der
     * bearbeitenden Person, entfernte gehen zurück zu der Person, die sie aufgestellt hat.
     *
     * @param  list<array{id?: int, item_id?: int, x: int, y: int, layer?: string, rotation?: int, mirrored?: bool, master?: bool}>  $placements
     */
    public function saveLayout(User $user, Room $room, int $version, array $placements): Room
    {
        if (! self::canEdit($user, $room)) {
            throw new GameException('Dieses Zimmer darfst du nicht einrichten.');
        }
        if (count($placements) > config('game.housing.max_items_per_room')) {
            throw new GameException('So viele Möbel passen nicht in ein Zimmer.');
        }

        return DB::transaction(function () use ($user, $room, $version, $placements) {
            $room = Room::lockForUpdate()->findOrFail($room->id);
            if ($room->version !== $version) {
                throw new LayoutConflict($room);
            }

            $existing = $room->placedItems()->get()->keyBy('id');
            $items = Item::whereIn('id', collect($placements)->map(fn ($p) => isset($p['id']) ? $existing->get($p['id'])?->item_id : ($p['item_id'] ?? null))->filter()->unique())
                ->get()->keyBy('id');

            $grid = [];
            $keep = [];
            $new = [];
            foreach ($placements as $i => $p) {
                $placed = isset($p['id']) ? ($existing[$p['id']] ?? throw new GameException('Ein Möbelstück gehört nicht in dieses Zimmer.')) : null;
                $item = $items[$placed?->item_id ?? ($p['item_id'] ?? 0)] ?? throw new GameException('Unbekanntes Möbelstück.');
                if ($item->type !== 'furniture') {
                    throw new GameException("„{$item->name}“ ist kein Möbelstück.");
                }

                $row = [
                    'x' => (int) $p['x'],
                    'y' => (int) $p['y'],
                    'layer' => $p['layer'] ?? ($item->stats['layers'][0] ?? 'floor'),
                    'rotation' => (int) ($p['rotation'] ?? 0),
                    'mirrored' => (bool) ($p['mirrored'] ?? false),
                ];
                $this->occupy($grid, $room, $item, $row);

                if ($placed) {
                    if (isset($keep[$placed->id])) {
                        throw new GameException('Ein Möbelstück steht doppelt im Plan.');
                    }
                    $keep[$placed->id] = $row;
                } else {
                    $new[] = $row + ['item_id' => $item->id, 'master' => (bool) ($p['master'] ?? false)];
                }
            }

            foreach ($existing as $id => $placed) {
                if (isset($keep[$id])) {
                    $placed->update($keep[$id]);

                    continue;
                }
                // Zurück ins Inventar der Person, die es aufgestellt hat (sonst an die bearbeitende Person)
                $owner = $placed->placed_by && User::where('id', $placed->placed_by)->where('family_id', $user->family_id)->exists() ? $placed->placed_by : $user->id;
                Stock::add($owner, $placed->item_id, 1, $placed->master);
                $placed->delete();
            }

            foreach ($new as $row) {
                Stock::take($user->id, $items[$row['item_id']], 1, $row['master']);
                $room->placedItems()->create($row + ['placed_by' => $user->id]);
            }

            $room->increment('version');

            return $room->refresh();
        });
    }

    public static function hasBed(User $user): bool
    {
        return PlacedItem::whereHas('item', fn ($q) => $q->where('key', 'furn_bed'))
            ->whereHas('room', fn ($q) => $q->where('type', 'bedroom')->where('owner_user_id', $user->id))->exists();
    }

    /** Trophäenhalle: besiegte Familien-Bosse und meisterhafte Stücke der Familie. */
    public static function trophies(Home $home): ?array
    {
        if (! self::hasRoom($home, 'trophy')) {
            return null;
        }

        return [
            'bosses' => Quest::where('family_id', $home->family_id)->where('scope', 'family')->where('type', 'boss')->where('status', 'completed')
                ->latest('completed_at')->limit(30)->get()
                ->map(fn (Quest $q) => ['key' => $q->quest_key, 'name' => $q->name, 'icon' => $q->icon, 'completed_at' => $q->completed_at?->toDateString()])->values(),
            'master_items' => UserItem::with('item')->where('master_quantity', '>', 0)
                ->whereIn('user_id', User::where('family_id', $home->family_id)->select('id'))->get()
                ->map(fn (UserItem $u) => ['key' => $u->item->key, 'name' => $u->item->name, 'icon' => $u->item->icon, 'owner_id' => $u->user_id])->values(),
            'achievements' => UserAchievement::whereIn('user_id', User::where('family_id', $home->family_id)->select('id'))->count(),
        ];
    }

    /** Grundfläche prüfen und im Raster belegen: innerhalb des Zimmers, erlaubte Ebene, keine Überlappung. */
    private function occupy(array &$grid, Room $room, Item $item, array $row): void
    {
        $layers = $item->stats['layers'] ?? ['floor'];
        if (! in_array($row['layer'], self::LAYERS, true) || ! in_array($row['layer'], $layers, true)) {
            throw new GameException("„{$item->name}“ passt nicht an diese Stelle.");
        }
        if (! in_array($row['rotation'], self::ROTATIONS, true)) {
            throw new GameException('Möbel lassen sich nur in 90°-Schritten drehen.');
        }

        [$w, $h] = self::footprint($item, $row['rotation']);
        if ($row['x'] < 0 || $row['y'] < 0 || $room->width < $row['x'] + $w || $room->height < $row['y'] + $h) {
            throw new GameException("„{$item->name}“ ragt über das Zimmer hinaus.");
        }

        for ($x = $row['x']; $x < $row['x'] + $w; $x++) {
            for ($y = $row['y']; $y < $row['y'] + $h; $y++) {
                if (isset($grid[$row['layer']][$x][$y])) {
                    throw new GameException("„{$item->name}“ überlappt mit „{$grid[$row['layer']][$x][$y]}“.");
                }
                $grid[$row['layer']][$x][$y] = $item->name;
            }
        }
    }

    /** @return array{0: int, 1: int} */
    public static function footprint(Item $item, int $rotation): array
    {
        $w = (int) ($item->stats['w'] ?? 1);
        $h = (int) ($item->stats['h'] ?? 1);

        return $rotation % 180 === 0 ? [$w, $h] : [$h, $w];
    }

    /**
     * Unordnung (§2.6): überfällige Haushalts-Familienaufgaben lassen Staub und Kisten erscheinen.
     *
     * @return array{level: int, overdue: int}
     */
    public static function clutter(int $familyId, ?string $today = null): array
    {
        $today ??= now()->toDateString();
        $overdue = Task::where('family_id', $familyId)->where('scope', 'family')->where('category', 'household')
            ->where('type', 'todo')->whereNull('archived_at')->whereNotNull('due_date')->where('due_date', '<', $today)
            ->where(fn ($q) => $q->doesntHave('assignments')->orWhereHas('assignments', fn ($a) => $a->where('completed', false)))
            ->count();

        return ['level' => match (true) {
            $overdue === 0 => 0,
            $overdue <= 2 => 1,
            $overdue <= 4 => 2,
            default => 3,
        }, 'overdue' => $overdue];
    }

    /** Haus für die eigene Familie bzw. als Besuch (nur ansehen, ohne Lager und Bauprojekte). */
    public static function present(Home $home, bool $visitor = false): array
    {
        $home->load(['rooms.placedItems.item', 'rooms.owner', 'family']);

        $data = [
            'family' => ['id' => $home->family_id, 'name' => $home->family->name],
            'stage' => $home->stage,
            'stage_name' => Home::STAGES[$home->stage],
            'stations' => (object) ($home->stations ?? []),
            'rooms' => $home->rooms
                ->filter(fn (Room $r) => $r->type !== 'bedroom' || $r->owner?->family_id === $home->family_id)
                ->map(fn (Room $r) => self::presentRoom($r))->values(),
            'clutter' => self::clutter($home->family_id),
            'trophies' => self::trophies($home),
            'guestbook' => GuestbookEntry::with('user')->where('family_id', $home->family_id)->latest('id')->limit(30)->get()
                ->map(fn (GuestbookEntry $e) => GuestbookService::presentEntry($e))->values(),
        ];

        if (! $visitor) {
            $data['projects'] = BuildService::presentFor($home);
            $data['garden'] = GardenService::present($home);
            $data['storage'] = StorageService::present($home->family_id);
        }

        return $data;
    }

    public static function presentRoom(Room $room): array
    {
        $room->loadMissing('placedItems.item', 'owner');

        return [
            'id' => $room->id,
            'type' => $room->type,
            'name' => $room->name ?? ($room->type === 'bedroom' && $room->owner ? "Zimmer von {$room->owner->name}" : Room::TYPES[$room->type]),
            'owner_id' => $room->owner_user_id,
            'width' => $room->width,
            'height' => $room->height,
            'version' => $room->version,
            'items' => $room->placedItems->map(fn (PlacedItem $p) => [
                'id' => $p->id,
                'item_id' => $p->item_id,
                'key' => $p->item->key,
                'name' => $p->item->name,
                'icon' => $p->item->icon,
                'w' => (int) ($p->item->stats['w'] ?? 1),
                'h' => (int) ($p->item->stats['h'] ?? 1),
                'x' => $p->x,
                'y' => $p->y,
                'layer' => $p->layer,
                'rotation' => $p->rotation,
                'mirrored' => $p->mirrored,
                'master' => $p->master,
            ])->values(),
        ];
    }
}
