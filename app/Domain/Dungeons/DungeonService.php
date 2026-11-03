<?php

namespace App\Domain\Dungeons;

use App\Domain\Events\EventEngine;
use App\Domain\Gameplay\Attributes;
use App\Domain\Gameplay\GameException;
use App\Domain\Gameplay\Progression;
use App\Domain\Integrations\HomeAssistant\HomeAssistantWebhooks;
use App\Domain\Inventory\Stock;
use App\Domain\Notifications\PushNotifier;
use App\Domain\Quests\QuestService;
use App\Domain\Social\FeedService;
use App\Domain\Stats\Counters;
use App\Enums\AgeGroup;
use App\Enums\FamilyRole;
use App\Http\Presenters\CharacterSheet;
use App\Models\Dungeon;
use App\Models\DungeonAction;
use App\Models\DungeonPosition;
use App\Models\Item;
use App\Models\Quest;
use App\Models\User;
use App\Models\UserStats;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Dungeon-Quests (Konzept §2.7): server-autoritativ, rundenbasiert und asynchron. Die App schickt nur Absichten
 * (move, interact, fight, use-item) mit client_uuid; der Server prüft Fackeln, Wege und würfelt deterministisch
 * aus Seed + Aktionszähler. Kein Game Over: bei 0 HP geht es zurück zum Eingang, mit Fackel-Verlust.
 */
class DungeonService
{
    public const ACTIONS = ['move', 'interact', 'fight', 'use-item'];

    public function __construct(
        private readonly FeedService $feed,
        private readonly QuestService $quests,
        private readonly PushNotifier $push,
    ) {}

    // ---------- Dungeons anlegen ----------

    /** Der Familien-Dungeon dieser Woche; Endboss ist der laufende Familien-Boss (falls es einen gibt). */
    public function current(User $user): Dungeon
    {
        $tz = $user->family?->timezone ?? 'Europe/Berlin';
        $week = CarbonImmutable::now($tz)->startOfWeek();

        return DB::transaction(function () use ($user, $week) {
            $existing = Dungeon::where('family_id', $user->family_id)->where('kind', 'family')->whereDate('starts_on', $week->toDateString())->lockForUpdate()->first();
            if ($existing) {
                return $existing;
            }

            $quest = $this->quests->activeFamily($user->family_id);
            $quest = $quest?->isBoss() ? $quest : null;
            $seed = Str::lower(Str::random(16));
            $theme = DungeonCatalog::themeForQuest($quest?->quest_key) ?? (new Rng($seed, 'theme'))->pick(DungeonCatalog::themeKeysFor($user->family_id));
            $mode = self::familyMode($user->family_id);

            return Dungeon::create([
                'family_id' => $user->family_id,
                'kind' => 'family',
                'theme' => $theme,
                'seed' => $seed,
                'mode' => $mode,
                'starts_on' => $week->toDateString(),
                'ends_at' => $week->endOfWeek()->utc(),
                'quest_id' => $quest?->id,
                'state' => DungeonGenerator::generate($seed, 'family', $mode, $theme),
            ]);
        });
    }

    /** Solo-Gewölbe (5 Räume) pro Tag – für alle, die ihre Dailies schon geschafft haben. */
    public function solo(User $user): Dungeon
    {
        $today = $user->gameDate();
        $existing = Dungeon::where('user_id', $user->id)->where('kind', 'solo')->whereDate('starts_on', $today->toDateString())->first();
        if ($existing) {
            return $existing;
        }

        [$due, $done] = HomeAssistantWebhooks::dailies($user);
        if ($due > $done) {
            throw new GameException('Das Solo-Gewölbe öffnet sich, wenn deine Dailies für heute geschafft sind.');
        }

        $seed = Str::lower(Str::random(16));
        $theme = (new Rng($seed, 'theme'))->pick(DungeonCatalog::themeKeysFor($user->family_id));
        $mode = $user->ageGroup() === AgeGroup::Child ? 'child' : 'teen';

        return Dungeon::create([
            'family_id' => $user->family_id,
            'user_id' => $user->id,
            'kind' => 'solo',
            'theme' => $theme,
            'seed' => $seed,
            'mode' => $mode,
            'starts_on' => $today->toDateString(),
            'ends_at' => $today->endOfDay()->utc(),
            'state' => DungeonGenerator::generate($seed, 'solo', $mode, $theme),
        ]);
    }

    /** Solo-Gewölbe bleiben nach dem Schatz bis zum Tagesende offen, damit man die übrigen Räume noch erkunden kann. */
    public static function playable(Dungeon $dungeon): bool
    {
        $open = $dungeon->status === 'active' || ($dungeon->kind === 'solo' && $dungeon->status === 'completed');

        return $open && ! $dungeon->ends_at->isPast();
    }

    /** Für 9–12 leichter, sobald ein Kind in der Familie mitspielt. */
    public static function familyMode(int $familyId): string
    {
        return User::where('family_id', $familyId)->where('family_role', '!=', FamilyRole::Guest)->get()
            ->contains(fn (User $u) => $u->ageGroup() === AgeGroup::Child) ? 'child' : 'teen';
    }

    // ---------- Züge ----------

    /** @return array{result: array, replayed: bool} */
    public function act(User $user, Dungeon $dungeon, string $action, array $input, ?string $clientUuid = null): array
    {
        if ($clientUuid && ($done = DungeonAction::where('user_id', $user->id)->where('client_uuid', $clientUuid)->first())) {
            return ['result' => $done->result, 'replayed' => true];
        }

        $result = DB::transaction(function () use ($user, $dungeon, $action, $input, $clientUuid) {
            $dungeon = Dungeon::lockForUpdate()->findOrFail($dungeon->id);
            $this->ensureAccess($user, $dungeon);
            if (! self::playable($dungeon)) {
                throw new GameException('Dieser Dungeon ist vorbei.');
            }

            $stats = UserStats::lockForUpdate()->findOrFail($user->id);
            $position = $this->position($dungeon, $user, $stats);
            $state = $dungeon->state;
            $dungeon->counter++;
            $rng = new Rng($dungeon->seed, $dungeon->counter);
            $context = new Turn($dungeon, $user, $stats, $position, $state, $rng);

            $result = match ($action) {
                'move' => $this->move($context, (int) ($input['to'] ?? -1)),
                'interact' => $this->interact($context, $input),
                'fight' => $this->fight($context, (string) ($input['move'] ?? 'attack')),
                'use-item' => $this->useItem($context, (int) ($input['item_id'] ?? 0), isset($input['to']) ? (int) $input['to'] : null),
                default => throw new GameException('Unbekannte Aktion.'),
            };

            $dungeon->state = $context->state;
            $dungeon->save();
            $context->stats->save();
            $context->position->save();
            foreach ($context->touched as $other) {
                $other->save();
            }

            DungeonAction::create([
                'dungeon_id' => $dungeon->id,
                'user_id' => $user->id,
                'client_uuid' => $clientUuid,
                'counter' => $dungeon->counter,
                'action' => $action,
                'room' => $context->position->room,
                'torches' => $context->spent,
                'result' => $result,
            ]);

            return $result;
        });

        return ['result' => $result, 'replayed' => false];
    }

    private function move(Turn $t, int $to): array
    {
        $from = $t->position->room;
        $edge = $t->edge($from, $to) ?? throw new GameException('Dorthin führt kein Weg.');

        match ($edge['kind']) {
            'locked' => throw new GameException('Die Tür ist verschlossen. Du brauchst einen Schlüssel.'),
            'gate' => throw new GameException('Ein schweres Tor. Irgendwo muss es einen Mechanismus geben.'),
            'cracked' => throw new GameException('Da ist nur eine rissige Wand. Ein Krieger könnte sie einschlagen.'),
            default => null,
        };

        $here = $t->room($from);
        if ($t->monsterAlive($here) && $to !== $t->position->previous_room) {
            throw new GameException('Der Weg ist versperrt! Besiege zuerst '.DungeonCatalog::monster($here['monster']['key'])['name'].' – oder geh zurück.');
        }

        $known = in_array($to, $t->state['revealed'], true);
        // Barde: Wege durch bekannte Räume kosten nichts, wenn ein Barde dabei ist (das „Lied“)
        $free = $known && ($t->class() === 'bard' || $t->othersIn($to)->contains(fn (DungeonPosition $p) => self::classOf($p->user) === 'bard'));
        $t->spend($free ? 0 : config('game.dungeon.cost.move'));

        $t->position->previous_room = $from;
        $t->position->room = $to;
        $t->position->fight = null;
        $result = ['action' => 'move', 'room' => $to, 'events' => []];

        $result['events'] = array_merge($result['events'], $this->reveal($t, $to));
        if ($t->class() === 'mage') {
            foreach ($t->neighbors($to) as $n) {
                $this->reveal($t, $n, announce: false);
            }
        }

        $room = $t->room($to);
        if ($room['type'] === 'trap' && ! $room['trap']['sprung']) {
            $result['events'][] = $this->springTrap($t, $to);
        }
        if ($room['type'] === 'boss') {
            $result['events'] = array_merge($result['events'], $this->checkBossUnlock($t));
        }

        return $result;
    }

    private function interact(Turn $t, array $input): array
    {
        $id = $t->position->room;
        $room = $t->room($id);

        // Krieger schlägt eine rissige Wand zum Nachbarraum ein
        if (isset($input['to'])) {
            $edge = $t->edge($id, (int) $input['to']);
            if (($edge['kind'] ?? null) !== 'cracked') {
                throw new GameException('Hier gibt es nichts einzuschlagen.');
            }
            if ($t->class() !== 'warrior') {
                throw new GameException('Die Wand ist zu fest. Ein Krieger schafft das!');
            }
            $t->spend(config('game.dungeon.cost.interact'));
            $t->setEdgeKind($id, (int) $input['to'], 'open');
            $this->reveal($t, (int) $input['to'], announce: false);

            return ['action' => 'interact', 'kind' => 'break', 'events' => ["{$t->user->name} hat eine Abkürzung freigeschlagen!"]];
        }

        return match ($room['type']) {
            'treasure' => $this->openChest($t, $id),
            'puzzle' => $this->solvePuzzle($t, $id, (string) ($input['answer'] ?? '')),
            'campfire' => $this->rest($t, $id),
            'merchant' => $this->buy($t, (string) ($input['buy'] ?? '')),
            'lever' => $this->pullLever($t, $id),
            'trap' => $this->disarm($t, $id),
            default => throw new GameException('Hier gibt es nichts zu tun.'),
        };
    }

    private function fight(Turn $t, string $move): array
    {
        $id = $t->position->room;
        $room = $t->room($id);
        if (! $t->monsterAlive($room)) {
            throw new GameException('Hier ist kein Gegner.');
        }
        if ($room['type'] === 'boss' && ! $t->dungeon->boss_unlocked) {
            throw new GameException('Der Boss wartet, bis die Familie da ist. Holt die anderen dazu!');
        }

        $monster = DungeonCatalog::monster($room['monster']['key']);
        $attrs = Attributes::for($t->stats);
        $t->spend(config('game.dungeon.cost.fight'));
        $log = [];

        $damage = 0.0;
        if ($move === 'potion') {
            $potion = Item::where('key', 'potion_health')->firstOrFail();
            Stock::take($t->user->id, $potion, 1);
            $heal = (float) ($potion->stats['heal'] ?? 15);
            $t->position->hp = min($t->maxHp(), $t->position->hp + $heal);
            $log[] = "{$t->user->name} trinkt einen Heiltrank (+{$heal} HP).";
        } elseif ($move === 'skill') {
            if ($t->stats->mp < config('game.dungeon.skill_mana')) {
                throw new GameException('Dafür reicht dein Mana nicht.');
            }
            $t->stats->mp -= config('game.dungeon.skill_mana');
            $damage = $t->rng->vary(6 + $attrs['int'] * 0.8);
        } elseif ($move === 'attack') {
            $damage = max(1, $t->rng->vary(4 + $attrs['str'] * 0.6 + $t->stats->level * 0.2) - $monster['defense']);
        } else {
            throw new GameException('Angriff, Fähigkeit oder Trank?');
        }

        if ($damage > 0) {
            // Tierbändiger: der Tierbegleiter beißt mit zu
            if ($t->class() === 'tamer') {
                $damage += 3;
            }
            // Waldläufer: Erstschlag – die erste Runde gegen ein Monster ist immer ein Volltreffer
            $firstRound = ($t->position->fight['room'] ?? null) !== $id;
            if (($t->class() === 'ranger' && $firstRound) || $t->rng->chance(0.05 + $attrs['per'] * 0.01)) {
                $damage *= 1.5;
                $log[] = 'Volltreffer!';
            }
            // Barde: Bonus, wenn mehrere im selben Raum stehen
            if ($t->othersIn($id)->isNotEmpty() && ($t->class() === 'bard' || $t->othersIn($id)->contains(fn ($p) => self::classOf($p->user) === 'bard'))) {
                $damage *= 1.15;
            }
            $damage = round($damage, 1);
            $log[] = "{$t->user->name} trifft {$monster['name']} für {$damage}.";
        }

        $result = ['action' => 'fight', 'move' => $move, 'damage' => $damage, 'events' => []];

        // Endboss = Familien-Boss: Dungeon-Schaden geht direkt auf die Quest
        $quest = $room['type'] === 'boss' && $t->dungeon->quest_id ? Quest::find($t->dungeon->quest_id) : null;
        if ($quest && $quest->status === 'active') {
            $applied = $damage > 0 ? $this->quests->damage($quest, $t->user, $damage) : 0;
            $quest->refresh();
            $t->state['rooms'][$id]['monster']['hp'] = round($quest->remaining(), 1);
            $t->state['rooms'][$id]['monster']['max_hp'] = $quest->goal;
            $result['boss_damage'] = $applied;
        } else {
            $t->state['rooms'][$id]['monster']['hp'] = max(0, round($room['monster']['hp'] - $damage, 1));
        }

        $t->position->fight = ['room' => $id, 'rounds' => (($t->position->fight['room'] ?? null) === $id ? $t->position->fight['rounds'] : 0) + 1];

        if ($t->state['rooms'][$id]['monster']['hp'] <= 0) {
            [$events, $loot] = $this->defeat($t, $id, $monster);
            $result['events'] = array_merge($log, $events);
            $result['loot'] = $loot;
            $result['found'] = self::lootList($loot);

            return $result;
        }

        // Gegenangriff – ein Krieger im Raum zieht ihn auf sich
        $target = $t->position;
        $warrior = $t->class() === 'warrior' ? null : $t->othersIn($id)->first(fn ($p) => self::classOf($p->user) === 'warrior');
        if ($warrior) {
            $target = $t->touch($warrior);
        }
        $targetStats = $target === $t->position ? $t->stats : UserStats::find($target->user_id);
        $hit = round(max(1, $t->rng->vary($monster['attack']) - Attributes::for($targetStats)['con'] * 0.3), 1);
        $targetName = $target === $t->position ? $t->user->name : $target->user->name;

        // Mönch: weicht jedem vierten Gegenangriff aus
        if ($targetStats->class_key === 'monk' && $t->rng->chance(0.25)) {
            $log[] = "{$targetName} weicht {$monster['name']} geschickt aus.";
            $result['events'] = $log;

            return $result;
        }
        // Paladin im Raum: Schutzaura −30 % Schaden
        if ($t->class() === 'paladin' || $t->othersIn($id)->contains(fn ($p) => self::classOf($p->user) === 'paladin')) {
            $hit = round(max(1, $hit * 0.7), 1);
        }
        $target->hp = round($target->hp - $hit, 1);
        $log[] = "{$monster['name']} trifft {$targetName} für {$hit}.";
        if ($target->hp <= 0) {
            $log = array_merge($log, $this->knockout($t, $target));
        }
        $result['events'] = $log;

        return $result;
    }

    private function useItem(Turn $t, int $itemId, ?int $to): array
    {
        $item = Item::find($itemId) ?? throw new GameException('Diesen Gegenstand gibt es nicht.');

        if ($item->key === 'dungeon_key') {
            if ($to === null || ($t->edge($t->position->room, $to)['kind'] ?? null) !== 'locked') {
                throw new GameException('Hier ist keine verschlossene Tür.');
            }
            Stock::take($t->user->id, $item, 1);
            $t->setEdgeKind($t->position->room, $to, 'open');

            return ['action' => 'use-item', 'item' => $item->key, 'events' => ["{$t->user->name} hat eine Tür aufgeschlossen."]];
        }

        if ($item->type !== 'potion' || empty($item->stats['heal'])) {
            throw new GameException('Das hilft im Dungeon nicht.');
        }
        Stock::take($t->user->id, $item, 1);
        $t->position->hp = min($t->maxHp(), $t->position->hp + (float) $item->stats['heal']);

        return ['action' => 'use-item', 'item' => $item->key, 'hp' => $t->position->hp, 'events' => []];
    }

    // ---------- Raum-Inhalte ----------

    /**
     * Jede Truhe ist für jede Person einmal voll: Wer sie öffnet, bekommt Gold und Material,
     * auch wenn schon jemand aus der Familie vorher da war. Der Schlüssel liegt nur einmal darin.
     */
    private function openChest(Turn $t, int $id): array
    {
        $room = $t->room($id);
        $uid = (string) $t->user->id;
        if (isset($room['chest']['opened_by'][$uid])) {
            throw new GameException('Diese Truhe hast du schon geplündert.');
        }
        $t->spend(config('game.dungeon.cost.interact'));
        $first = empty($room['chest']['opened']);

        $teen = $t->dungeon->mode === 'teen';
        $loot = $this->rollLoot($t, $t->rng->int(2, 3), rare: $teen ? 0.15 : 0.10);
        if ($first && ! empty($room['key_drop'])) {
            $loot['dungeon_key'] = ($loot['dungeon_key'] ?? 0) + 1;
        }
        $gold = $teen ? $t->rng->int(8, 20) : $t->rng->int(5, 15);
        Stock::addKeys($t->user->id, $loot);
        Progression::changeGold($t->stats, $gold);
        $t->state['rooms'][$id]['chest']['opened'] = true;
        $t->state['rooms'][$id]['chest']['opened_by'][$uid] = ['gold' => $gold, 'loot' => $loot];
        $events = ["{$t->user->name} öffnet eine Truhe und findet {$gold} Gold."];

        if ($first && $t->dungeon->kind === 'solo' && $id === $t->state['final']) {
            $events = array_merge($events, $this->complete($t, 'solo'));
        }

        return ['action' => 'interact', 'kind' => 'chest', 'loot' => $loot, 'gold' => $gold, 'found' => self::lootList($loot), 'events' => $events];
    }

    /** Beute mit Namen und Symbol für die Anzeige */
    public static function lootList(array $loot): array
    {
        $items = Item::whereIn('key', array_keys($loot))->get(['key', 'name', 'icon'])->keyBy('key');

        return collect($loot)->map(fn ($amount, $key) => [
            'key' => $key, 'amount' => (int) $amount, 'name' => $items[$key]->name ?? $key, 'icon' => $items[$key]->icon ?? '📦',
        ])->values()->all();
    }

    private function solvePuzzle(Turn $t, int $id, string $answer): array
    {
        $puzzle = $t->room($id)['puzzle'];
        if ($puzzle['solved']) {
            throw new GameException('Dieses Rätsel ist schon gelöst.');
        }
        $t->spend(config('game.dungeon.cost.interact'));

        if (trim($answer) !== $puzzle['answer']) {
            return ['action' => 'interact', 'kind' => 'puzzle', 'solved' => false, 'events' => ['Leider nicht – versuch es noch einmal.']];
        }
        $t->state['rooms'][$id]['puzzle']['solved'] = true;
        $loot = $this->rollLoot($t, 2, rare: 0.25);
        Stock::addKeys($t->user->id, $loot);

        return ['action' => 'interact', 'kind' => 'puzzle', 'solved' => true, 'loot' => $loot, 'found' => self::lootList($loot), 'events' => ["{$t->user->name} hat das Rätsel gelöst!"]];
    }

    /** Lagerfeuer: heilt einmal am Tag; Heiler heilen alle im Raum voll (ohne Fackel). */
    private function rest(Turn $t, int $id): array
    {
        $today = $t->user->gameDate()->toDateString();
        $used = (array) ($t->room($id)['campfire']['used'] ?? []);
        if (($used[$t->user->id] ?? null) === $today) {
            throw new GameException('Du hast dich heute schon am Feuer ausgeruht.');
        }
        $healer = $t->class() === 'healer';
        $t->spend($healer ? 0 : config('game.dungeon.cost.interact'));
        $used[$t->user->id] = $today;
        $t->state['rooms'][$id]['campfire']['used'] = $used;

        // Mittsommer (§12.4): Lagerfeuer heilen doppelt
        $heal = $t->maxHp() * 0.5 * EventEngine::modifier($t->user->family_id, 'campfire_heal');
        $t->position->hp = $healer ? $t->maxHp() : min($t->maxHp(), $t->position->hp + $heal);
        if ($healer) {
            foreach ($t->othersIn($id) as $other) {
                $t->touch($other)->hp = self::maxHpFor(UserStats::find($other->user_id));
            }
        }
        $events = [$healer ? "{$t->user->name} heilt alle am Lagerfeuer." : "{$t->user->name} ruht sich am Feuer aus."];

        // Koch: Proviant – alle im Raum bekommen eine Fackel (bis zum Vorratslimit)
        if ($t->class() === 'cook') {
            $max = config('game.dungeon.torch_store_max');
            $t->stats->torches = min($max, $t->stats->torches + 1);
            foreach ($t->othersIn($id) as $other) {
                $stats = UserStats::lockForUpdate()->find($other->user_id);
                $stats->torches = min($max, $stats->torches + 1);
                $stats->save();
            }
            $events[] = "{$t->user->name} kocht Proviant – eine Fackel für alle am Feuer.";
        }

        return ['action' => 'interact', 'kind' => 'campfire', 'events' => $events];
    }

    private function buy(Turn $t, string $key): array
    {
        $prices = DungeonCatalog::all()['merchant'];
        if (! isset($prices[$key])) {
            throw new GameException('Das verkauft der Händler nicht.');
        }
        if ($t->stats->gold < $prices[$key]) {
            throw new GameException('Dafür reicht dein Gold nicht.');
        }
        Progression::changeGold($t->stats, -$prices[$key]);
        Stock::addKeys($t->user->id, [$key => 1]);

        return ['action' => 'interact', 'kind' => 'merchant', 'bought' => $key, 'events' => []];
    }

    /** Tüftler bedienen Hebel; über Home Assistant geht das mit einem echten Taster oder NFC-Tag. */
    private function pullLever(Turn $t, int $id): array
    {
        if ($t->room($id)['lever']['pulled']) {
            throw new GameException('Der Hebel ist schon umgelegt.');
        }
        if ($t->class() !== 'tinkerer') {
            throw new GameException('Ein komplizierter Mechanismus. Den versteht nur ein Tüftler – oder ein echter Schalter zu Hause.');
        }
        $t->spend(config('game.dungeon.cost.interact'));
        $t->state['rooms'][$id]['lever']['pulled'] = true;
        $t->openGates();

        return ['action' => 'interact', 'kind' => 'lever', 'events' => ["{$t->user->name} hat einen Mechanismus ausgelöst. Irgendwo öffnet sich ein Tor!"]];
    }

    private function disarm(Turn $t, int $id): array
    {
        if ($t->room($id)['trap']['sprung']) {
            throw new GameException('Diese Falle ist schon entschärft.');
        }

        return ['action' => 'interact', 'kind' => 'trap', 'events' => [$this->springTrap($t, $id)]];
    }

    /** Spürsinn-Probe; Schurken entschärfen jede Falle. */
    private function springTrap(Turn $t, int $id): string
    {
        $trap = $t->room($id)['trap'];
        $t->state['rooms'][$id]['trap']['sprung'] = true;
        if ($t->class() === 'rogue') {
            return "{$t->user->name} entschärft die Falle geschickt.";
        }

        $chance = 1 - $trap['difficulty'] + Attributes::for($t->stats)['per'] * 0.03 - 0.1;
        if ($t->rng->chance($chance)) {
            return "{$t->user->name} bemerkt die Falle rechtzeitig.";
        }

        $hit = $t->dungeon->mode === 'child' ? 3 : 6;
        $t->position->hp = round($t->position->hp - $hit, 1);
        $text = "Eine Falle! {$t->user->name} verliert {$hit} HP.";
        if ($t->position->hp <= 0) {
            $text .= ' '.implode(' ', $this->knockout($t, $t->position));
        }

        return $text;
    }

    private function defeat(Turn $t, int $id, array $monster): array
    {
        $room = $t->room($id);
        $t->position->fight = null;
        $events = ["{$monster['name']} ist besiegt!"];
        Counters::bump($t->user->id, 'monsters_defeated');

        if ($room['type'] === 'boss') {
            return [array_merge($events, $this->complete($t, 'boss')), []];
        }

        $elite = $room['type'] === 'elite';
        $loot = $this->rollLoot($t, $elite ? 2 : 1, rare: $elite ? 0.3 : 0.05);
        if (! empty($room['key_drop'])) {
            $loot['dungeon_key'] = ($loot['dungeon_key'] ?? 0) + 1;
            $events[] = 'Es hat einen Schlüssel fallen lassen!';
        }
        Stock::addKeys($t->user->id, $loot);
        Progression::changeGold($t->stats, $elite ? 6 : 2);
        Progression::gainXp($t->stats, $elite ? 15 : 5);
        if ($elite) {
            $this->feed->post($t->dungeon->family_id, $t->user, 'dungeon_elite', $monster['icon'], "{$t->user->name} hat im Dungeon {$monster['name']} besiegt!");
        }

        return [$events, $loot];
    }

    /** Boss besiegt bzw. Solo-Schatz gefunden: Dungeon geschafft, Belohnung für alle Beteiligten. */
    private function complete(Turn $t, string $how): array
    {
        $t->dungeon->status = 'completed';
        $t->dungeon->completed_at = now();
        $reward = config($how === 'boss' ? 'game.dungeon.boss_reward' : 'game.dungeon.solo_reward');

        // Mit verknüpfter Quest verteilt die Quest selbst ihre Belohnung; der Dungeon gibt nur etwas obendrauf
        $participants = DungeonPosition::where('dungeon_id', $t->dungeon->id)->pluck('user_id');
        foreach ($participants as $userId) {
            $stats = $userId === $t->user->id ? $t->stats : UserStats::lockForUpdate()->find($userId);
            if ($stats === null) {
                continue;
            }
            Progression::changeGold($stats, $reward['gold']);
            Progression::gainXp($stats, $reward['xp']);
            if ($stats !== $t->stats) {
                $stats->save();
            }
        }

        $theme = DungeonCatalog::theme($t->dungeon->theme);
        $text = $how === 'boss'
            ? "Sieg im Dungeon „{$theme['name']}“! Die Familie hat den Endboss geschafft."
            : "{$t->user->name} hat das Solo-Gewölbe gemeistert.";
        $this->feed->post($t->dungeon->family_id, $t->user, 'dungeon_completed', $theme['icon'], $text);

        return [$text];
    }

    /** 0 HP: zurück zum Eingang, Fackeln weg, aber nichts verloren. Ein Heiler im Raum fängt einen auf. */
    private function knockout(Turn $t, DungeonPosition $target): array
    {
        $targetUser = $target === $t->position ? $t->user : $target->user;
        $healerHere = DungeonPosition::with('user')->where('dungeon_id', $t->dungeon->id)->where('room', $target->room)
            ->where('user_id', '!=', $target->user_id)->get()->first(fn ($p) => self::classOf($p->user) === 'healer');
        $stats = $target === $t->position ? $t->stats : UserStats::lockForUpdate()->find($target->user_id);

        if ($healerHere) {
            $target->hp = round(self::maxHpFor($stats) * 0.3, 1);

            return ["{$healerHere->user->name} belebt {$targetUser->name} wieder!"];
        }

        $loss = min($stats->torches, config('game.dungeon.knockout_torch_loss'));
        $stats->torches -= $loss;
        if ($stats !== $t->stats) {
            $stats->save();
        }
        $target->room = $t->state['entrance'];
        $target->previous_room = null;
        $target->fight = null;
        $target->hp = self::maxHpFor($stats);
        $target->knockouts++;

        return ["{$targetUser->name} ist K.O. und wacht am Eingang wieder auf ({$loss} Fackeln verloren)."];
    }

    /** @return list<string> */
    private function reveal(Turn $t, int $id, bool $announce = true): array
    {
        if (in_array($id, $t->state['revealed'], true)) {
            return [];
        }
        $t->state['revealed'][] = $id;
        $room = $t->room($id);
        if (! $announce || $t->dungeon->kind !== 'family') {
            return [];
        }

        $text = match ($room['type']) {
            'treasure' => "{$t->user->name} hat eine Schatzkammer gefunden!",
            'boss' => "{$t->user->name} hat den Raum des Endbosses entdeckt!",
            'merchant' => "{$t->user->name} hat einen Händler entdeckt.",
            default => null,
        };
        if ($text) {
            $this->feed->post($t->dungeon->family_id, $t->user, 'dungeon_discovery', DungeonCatalog::theme($t->dungeon->theme)['icon'], $text);
            if ($room['type'] === 'boss') {
                $this->push->notify(User::where('family_id', $t->dungeon->family_id)->where('id', '!=', $t->user->id)->get(), 'dungeon');
            }
        }

        return $text ? [$text] : [];
    }

    /** Der Boss-Kampf öffnet sich, wenn die Familie angekommen ist: mindestens zwei (bzw. alle, wenn nur eine Person mitspielt). */
    private function checkBossUnlock(Turn $t): array
    {
        if ($t->dungeon->boss_unlocked) {
            return [];
        }
        $participants = DungeonPosition::where('dungeon_id', $t->dungeon->id)->count();
        $here = $t->othersIn($t->position->room)->count() + 1;
        if ($here < min(2, $participants)) {
            return ['Der Boss wartet. Hier müssen mindestens zwei aus der Familie stehen.'];
        }
        $t->dungeon->boss_unlocked = true;
        $this->feed->post($t->dungeon->family_id, $t->user, 'dungeon_boss', '⚔️', 'Die Familie steht vor dem Endboss – der Kampf beginnt!');

        return ['Der Kampf gegen den Endboss ist freigeschaltet!'];
    }

    /** @return array<string, int> */
    private function rollLoot(Turn $t, int $count, float $rare): array
    {
        $theme = DungeonCatalog::theme($t->dungeon->theme);
        $loot = [];
        for ($i = 0; $i < $count; $i++) {
            $key = $t->rng->weighted($theme['loot']);
            $loot[$key] = ($loot[$key] ?? 0) + 1;
        }
        if ($t->rng->chance($rare)) {
            $key = $t->rng->weighted(DungeonCatalog::all()['rare_loot']);
            $loot[$key] = ($loot[$key] ?? 0) + 1;
        }

        return $loot;
    }

    // ---------- Home Assistant, Zeitsteuerung ----------

    /** Ein echter Taster/NFC-Tag (über HA) öffnet die Tore im Familien-Dungeon. */
    public function triggerMechanism(int $familyId): int
    {
        return DB::transaction(function () use ($familyId) {
            $dungeon = Dungeon::where('family_id', $familyId)->where('kind', 'family')->where('status', 'active')->lockForUpdate()->latest('id')->first();
            if ($dungeon === null) {
                throw new GameException('Gerade läuft kein Familien-Dungeon.');
            }
            $state = $dungeon->state;
            $opened = 0;
            foreach ($state['edges'] as $i => $e) {
                if ($e['kind'] === 'gate') {
                    $state['edges'][$i]['kind'] = 'open';
                    $opened++;
                }
            }
            foreach ($state['rooms'] as $i => $r) {
                if ($r['type'] === 'lever') {
                    $state['rooms'][$i]['lever']['pulled'] = true;
                }
            }
            $dungeon->update(['state' => $state]);
            if ($opened > 0) {
                $this->feed->post($familyId, null, 'dungeon_mechanism', '⚙️', 'Ein Mechanismus zu Hause hat im Dungeon ein Tor geöffnet!');
            }

            return $opened;
        });
    }

    public function expire(): int
    {
        return Dungeon::where('status', 'active')->where('ends_at', '<=', now())->update(['status' => 'expired']);
    }

    // ---------- Hilfen ----------

    private function ensureAccess(User $user, Dungeon $dungeon): void
    {
        $ok = $dungeon->kind === 'solo'
            ? $dungeon->user_id === $user->id
            : $dungeon->family_id === $user->family_id && $user->family_role !== FamilyRole::Guest;
        if (! $ok) {
            throw new GameException('Das ist nicht euer Dungeon.');
        }
    }

    private function position(Dungeon $dungeon, User $user, UserStats $stats): DungeonPosition
    {
        return DungeonPosition::with('user')->where('dungeon_id', $dungeon->id)->where('user_id', $user->id)->lockForUpdate()->first()
            ?? DungeonPosition::create(['dungeon_id' => $dungeon->id, 'user_id' => $user->id, 'room' => $dungeon->state['entrance'], 'hp' => self::maxHpFor($stats)])->load('user');
    }

    public static function maxHpFor(?UserStats $stats): float
    {
        return $stats ? (float) config('game.dungeon.hp') + Attributes::for($stats)['con'] * 2 : (float) config('game.dungeon.hp');
    }

    public static function classOf(?User $user): ?string
    {
        return $user ? UserStats::whereKey($user->id)->value('class_key') : null;
    }

    // ---------- Darstellung ----------

    public static function present(Dungeon $dungeon, User $viewer): array
    {
        $state = $dungeon->state;
        $revealed = $state['revealed'];
        $class = self::classOf($viewer);
        $theme = DungeonCatalog::theme($dungeon->theme);
        $positions = DungeonPosition::with('user')->where('dungeon_id', $dungeon->id)->get();
        $visible = collect($state['edges'])->filter(fn ($e) => in_array($e['a'], $revealed, true) || in_array($e['b'], $revealed, true));
        $fog = $visible->flatMap(fn ($e) => [$e['a'], $e['b']])->unique()->diff($revealed);
        $me = $positions->firstWhere('user_id', $viewer->id);
        $stats = UserStats::find($viewer->id);

        $rooms = collect($state['rooms'])->keyBy('id')->all();
        $rooms = collect($state['rooms'])->map(function (array $r) use ($revealed, $fog, $class, $dungeon, $visible, $rooms, $viewer) {
            if (! in_array($r['id'], $revealed, true)) {
                return $fog->contains($r['id']) ? ['id' => $r['id'], 'x' => $r['x'], 'y' => $r['y'], 'fog' => true] : null;
            }
            $room = ['id' => $r['id'], 'x' => $r['x'], 'y' => $r['y'], 'type' => $r['type'], 'block' => $r['block'], 'fog' => false,
                'block_url' => asset("dungeon/blocks/{$r['block']}.tmx"), 'doors' => self::doors($r, $visible->all(), $rooms)];
            if (isset($r['monster'])) {
                $m = DungeonCatalog::monster($r['monster']['key']);
                $room['monster'] = ['key' => $m['key'], 'name' => $m['name'], 'icon' => $m['icon'], 'hp' => $r['monster']['hp'], 'max_hp' => $r['monster']['max_hp'],
                    'art' => DungeonCatalog::monsterArt($m['key']), 'art_url' => route('api.v1.monsters.art', $m['key'])];
            }
            if (isset($r['chest'])) {
                // Truhen sind je Person gefüllt: „geöffnet“ heißt hier „von dir geöffnet“
                $mine = $r['chest']['opened_by'][(string) $viewer->id] ?? null;
                $room['chest'] = ['opened' => $mine !== null, 'gold' => $mine['gold'] ?? null, 'found' => $mine ? self::lootList($mine['loot']) : []];
            }
            if (isset($r['trap'])) {
                $room['trap'] = ['sprung' => $r['trap']['sprung']];
            }
            if (isset($r['puzzle'])) {
                // Magier lesen Runen (Hinweis), für 9–12 gibt es den Hinweis immer
                $room['puzzle'] = ['question' => $r['puzzle']['question'], 'solved' => $r['puzzle']['solved'], 'hint' => $class === 'mage' || $dungeon->mode === 'child' ? $r['puzzle']['hint'] : null];
            }
            if (isset($r['lever'])) {
                $room['lever'] = ['pulled' => $r['lever']['pulled']];
            }
            if ($r['type'] === 'merchant') {
                $room['offers'] = DungeonCatalog::all()['merchant'];
            }

            return $room;
        })->filter()->values();

        return [
            'id' => $dungeon->id,
            'kind' => $dungeon->kind,
            'theme' => ['key' => $theme['key'], 'name' => $theme['name'], 'icon' => $theme['icon']],
            'mode' => $dungeon->mode,
            'status' => $dungeon->status,
            'ends_at' => $dungeon->ends_at->toIso8601String(),
            'boss_unlocked' => $dungeon->boss_unlocked,
            'playable' => self::playable($dungeon),
            'quest_id' => $dungeon->quest_id,
            'rooms' => $rooms,
            'edges' => $visible->map(fn ($e) => ['a' => $e['a'], 'b' => $e['b'], 'kind' => $e['kind'], 'was' => $e['was'] ?? null])->values(),
            'art' => DungeonCatalog::art(),
            'members' => $positions->map(fn (DungeonPosition $p) => [
                'user_id' => $p->user_id, 'name' => $p->user?->name, 'room' => $p->room, 'hp' => $p->hp, 'class' => self::classOf($p->user),
                // Echter Avatar der Mitspielenden wie im Board
                'appearance' => $p->user ? CharacterSheet::appearance($p->user) : null,
            ])->values(),
            'me' => [
                'room' => $me?->room ?? $state['entrance'],
                'hp' => $me?->hp ?? self::maxHpFor($stats),
                'max_hp' => self::maxHpFor($stats),
                'torches' => $stats?->torches ?? 0,
                'torches_today' => $stats?->torches_on?->isSameDay($viewer->gameDate()) ? $stats->torches_today : 0,
                'torch_daily_max' => config('game.dungeon.torch_daily_max'),
                'class' => $class,
            ],
            'last_action_id' => DungeonAction::where('dungeon_id', $dungeon->id)->max('id'),
        ];
    }

    /**
     * Türen eines Raums je Himmelsrichtung (N = y-1, W = x-1) mit Art und Sprite-Schlüssel aus dungeons.json "art".
     * Seiten ohne Verbindung bekommen null, der Client setzt dort eine Wand.
     */
    private static function doors(array $room, array $edges, array $rooms): array
    {
        $art = DungeonCatalog::all()['art'];
        $doors = ['N' => null, 'E' => null, 'S' => null, 'W' => null];
        foreach ($edges as $e) {
            if ($e['a'] !== $room['id'] && $e['b'] !== $room['id']) {
                continue;
            }
            $other = $rooms[$e['a'] === $room['id'] ? $e['b'] : $e['a']];
            $side = match ([$other['x'] - $room['x'], $other['y'] - $room['y']]) {
                [0, -1] => 'N', [1, 0] => 'E', [0, 1] => 'S', [-1, 0] => 'W', default => null,
            };
            if ($side === null) {
                continue;
            }
            $was = $e['was'] ?? null;
            $sprite = $e['kind'] === 'open' && $was && $was !== 'open' ? $art['edges']["opened_{$was}"] : $art['edges'][$e['kind']];
            $doors[$side] = ['to' => $other['id'], 'kind' => $e['kind'], 'sprite' => "{$sprite}_{$art['side_suffix'][$side]}"];
        }

        return $doors;
    }

    public static function log(Dungeon $dungeon, int $since = 0): array
    {
        return DungeonAction::with('user')->where('dungeon_id', $dungeon->id)->where('id', '>', $since)->orderBy('id')->limit(100)->get()
            ->map(fn (DungeonAction $a) => [
                'id' => $a->id, 'user' => $a->user?->name, 'action' => $a->action, 'room' => $a->room,
                'events' => array_values(array_filter($a->result['events'] ?? [], 'is_string')), 'at' => $a->created_at?->toIso8601String(),
            ])->values()->all();
    }
}
