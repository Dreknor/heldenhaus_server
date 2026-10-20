<?php

use App\Domain\Dungeons\DungeonGenerator;
use App\Domain\Dungeons\Rng;
use App\Domain\Dungeons\Torches;
use App\Domain\Inventory\Stock;
use App\Domain\Quests\QuestService;
use App\Models\Dungeon;
use App\Models\DungeonPosition;
use App\Models\FeedEntry;
use App\Models\Item;
use App\Models\TaskCompletion;
use App\Models\UserStats;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->artisan('game:content-sync')->assertSuccessful();
    [$this->family, $this->parent, $this->child] = makeFamily();
});

/**
 * Feste Testkarte:
 *   2(Schatz, verschlossen) – 0(Eingang) – 1(Monster, Schlüssel) – 3(Boss)
 *                             |              ¦ rissig
 *                             4(Falle)  –  5(Rätsel) –gate– 6(Schatz)   7(Hebel) an 4
 */
function fixtureDungeon($family, ?int $questId = null, string $kind = 'family', ?int $userId = null): Dungeon
{
    $room = fn ($id, $x, $y, $type, $extra = []) => ['id' => $id, 'x' => $x, 'y' => $y, 'type' => $type, 'block' => $type] + $extra;

    return Dungeon::create([
        'family_id' => $family->id, 'user_id' => $userId, 'kind' => $kind, 'theme' => 'dust_crypt', 'seed' => 'fixedseed', 'mode' => 'child',
        'starts_on' => now()->startOfWeek(), 'ends_at' => now()->endOfWeek(), 'quest_id' => $questId,
        'state' => [
            'rooms' => [
                $room(0, 0, 0, 'entrance'),
                $room(1, 1, 0, 'monster', ['monster' => ['key' => 'dust_bunny', 'hp' => 8, 'max_hp' => 8], 'key_drop' => true]),
                $room(2, -1, 0, 'treasure', ['chest' => ['opened' => false]]),
                $room(3, 2, 0, 'boss', ['monster' => ['key' => 'dust_lord', 'hp' => 40, 'max_hp' => 40]]),
                $room(4, 0, 1, 'trap', ['trap' => ['sprung' => false, 'difficulty' => 1.5]]),
                $room(5, 1, 1, 'puzzle', ['puzzle' => ['question' => '2 + 3 = ?', 'answer' => '5', 'hint' => 'Finger!', 'solved' => false]]),
                $room(6, 2, 1, 'treasure', ['chest' => ['opened' => false]]),
                $room(7, 0, 2, 'lever', ['lever' => ['pulled' => false]]),
            ],
            'edges' => [
                ['a' => 0, 'b' => 1, 'kind' => 'open'], ['a' => 0, 'b' => 2, 'kind' => 'locked'], ['a' => 1, 'b' => 3, 'kind' => 'open'],
                ['a' => 0, 'b' => 4, 'kind' => 'open'], ['a' => 4, 'b' => 5, 'kind' => 'open'], ['a' => 5, 'b' => 6, 'kind' => 'gate'],
                ['a' => 1, 'b' => 5, 'kind' => 'cracked'], ['a' => 4, 'b' => 7, 'kind' => 'open'],
            ],
            'revealed' => [0], 'entrance' => 0, 'final' => 3,
        ],
    ]);
}

function torches($user, int $n): void
{
    UserStats::whereKey($user->id)->update(['torches' => $n]);
}

function act($user, Dungeon $d, string $action, array $input = [])
{
    Sanctum::actingAs($user, ['app']);

    return test()->postJson("/api/v1/dungeons/{$d->id}/{$action}", $input);
}

it('erzeugt aus demselben Seed denselben Dungeon, in dem jeder Raum erreichbar ist', function () {
    foreach (['a1', 'b2', 'c3', 'd4', 'e5', 'f6', 'g7', 'h8'] as $seed) {
        foreach (['child', 'teen'] as $mode) {
            $state = DungeonGenerator::generate($seed, 'family', $mode, 'spider_cellar');
            expect(DungeonGenerator::generate($seed, 'family', $mode, 'spider_cellar'))->toBe($state);

            $n = count($state['rooms']);
            expect($n)->toBeBetween($mode === 'teen' ? 9 : 6, $mode === 'teen' ? 12 : 8)
                ->and(DungeonGenerator::reachable($state, ['open', 'locked', 'gate']))->toBe(range(0, $n - 1))
                ->and(collect($state['rooms'])->where('type', 'boss'))->toHaveCount(1)
                ->and(collect($state['rooms'])->where('type', 'entrance'))->toHaveCount(1);

            // Verschlossenes liegt nur in Sackgassen: ohne Schlüssel fehlen höchstens diese Räume
            $locked = collect($state['edges'])->whereIn('kind', ['locked', 'gate'])->count();
            expect(count(DungeonGenerator::reachable($state)))->toBe($n - $locked);
            if (collect($state['edges'])->contains('kind', 'locked')) {
                expect(collect($state['rooms'])->contains(fn ($r) => ! empty($r['key_drop'])))->toBeTrue();
            }
        }
    }
    expect(DungeonGenerator::generate('x', 'solo', 'child', 'dust_crypt')['rooms'])->toHaveCount(3)
        ->and(DungeonGenerator::generate('a1', 'family', 'child', 'dust_crypt'))->not->toBe(DungeonGenerator::generate('a2', 'family', 'child', 'dust_crypt'));
});

it('würfelt deterministisch aus Seed und Aktionszähler', function () {
    $a = new Rng('seed', 5);
    $b = new Rng('seed', 5);
    expect([$a->float(), $a->int(1, 100)])->toBe([$b->float(), $b->int(1, 100)])
        ->and((new Rng('seed', 6))->float())->not->toBe((new Rng('seed', 5))->float());
});

it('gibt Fackeln nur für erledigte Aufgaben, mit Tages- und Vorratslimit', function () {
    $hard = makeTask($this->child, ['difficulty' => 2.0]);
    $this->actingAs($this->child)->post('/assignments/'.assignmentOf($hard, $this->child)->id.'/score/up');
    expect(UserStats::find($this->child->id)->torches)->toBe(3);

    expect(Torches::award($this->child, 20))->toBe(9) // Tageslimit 12
        ->and(UserStats::find($this->child->id)->torches_today)->toBe(12);

    $this->travel(1)->days();
    UserStats::whereKey($this->child->id)->update(['torches' => 19]);
    expect(Torches::award($this->child, 5))->toBe(1); // Vorrat 20

    // Mit Freigabe gibt es die Fackeln erst nach der Bestätigung
    $family = makeTask($this->parent, ['title' => 'Müll', 'scope' => 'family', 'assignees' => [$this->child->id], 'approval_mode' => 'required', 'difficulty' => 1.5]);
    UserStats::whereKey($this->child->id)->update(['torches' => 0]);
    $this->actingAs($this->child)->post('/assignments/'.assignmentOf($family, $this->child)->id.'/score/up');
    expect(UserStats::find($this->child->id)->torches)->toBe(0);
    $completion = TaskCompletion::latest('id')->first();
    $this->actingAs($this->parent)->post("/completions/{$completion->id}/approve");
    expect(UserStats::find($this->child->id)->torches)->toBe(2);
});

it('legt pro Woche einen Familien-Dungeon an, dessen Endboss der Familien-Boss ist', function () {
    $quest = app(QuestService::class)->start($this->parent, 'dust_monster', 'family');
    Sanctum::actingAs($this->child, ['app']);

    $first = $this->getJson('/api/v1/dungeons/current')->assertOk();
    $again = $this->getJson('/api/v1/dungeons/current')->assertOk();

    expect($again['id'])->toBe($first['id'])
        ->and($first['theme']['key'])->toBe('dust_crypt')   // Staubmonster → Staubmonster-Gruft
        ->and($first['quest_id'])->toBe($quest->id)
        ->and($first['mode'])->toBe('child')
        ->and(collect($first['rooms'])->where('fog', false))->toHaveCount(1)  // Nebel: nur der Eingang
        ->and(collect($first['rooms'])->where('fog', true))->not->toBeEmpty();
});

it('prüft Züge: nur benachbart, nicht durch Türen und Wände, nur mit Fackeln – und idempotent', function () {
    $d = fixtureDungeon($this->family);
    act($this->child, $d, 'move', ['to' => 1])->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'Fackeln'));

    torches($this->child, 5);
    act($this->child, $d, 'move', ['to' => 3])->assertStatus(422);  // nicht benachbart
    act($this->child, $d, 'move', ['to' => 2])->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'verschlossen'));

    $uuid = (string) Str::uuid();
    act($this->child, $d, 'move', ['to' => 1, 'client_uuid' => $uuid])->assertOk()->assertJsonPath('dungeon.me.room', 1);
    act($this->child, $d, 'move', ['to' => 1, 'client_uuid' => $uuid])->assertOk()->assertJsonPath('replayed', true);
    expect(UserStats::find($this->child->id)->torches)->toBe(4);

    // Monster versperrt den Weg weiter, zurück geht immer; rissige Wand nur für Krieger
    act($this->child, $d, 'move', ['to' => 3])->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'versperrt'));
    act($this->child, $d, 'interact', ['to' => 5])->assertStatus(422);
    act($this->child, $d, 'move', ['to' => 0])->assertOk();
});

it('lässt kämpfen, Beute und den Schlüssel finden und die Tür aufschließen', function () {
    $d = fixtureDungeon($this->family);
    torches($this->child, 20);
    act($this->child, $d, 'move', ['to' => 1])->assertOk();

    $rounds = 0;
    do {
        $res = act($this->child, $d, 'fight', ['move' => 'attack'])->assertOk();
        $rounds++;
    } while (collect($res['dungeon']['rooms'])->firstWhere('id', 1)['monster']['hp'] > 0 && $rounds < 10);

    expect($res['result']['loot']['dungeon_key'])->toBe(1)
        ->and(Stock::counts($this->child->id, ['dungeon_key'])['dungeon_key'])->toBe(1);
    act($this->child, $d, 'fight')->assertStatus(422); // kein Gegner mehr

    act($this->child, $d, 'move', ['to' => 0])->assertOk();
    act($this->child, $d, 'use-item', ['item_id' => Item::firstWhere('key', 'dungeon_key')->id, 'to' => 2])->assertOk();
    act($this->child, $d, 'move', ['to' => 2])->assertOk();
    $chest = act($this->child, $d, 'interact')->assertOk();
    expect(array_sum($chest['result']['loot']))->toBeGreaterThanOrEqual(2);
    act($this->child, $d, 'interact')->assertStatus(422); // leer
    expect(FeedEntry::where('type', 'dungeon_discovery')->count())->toBe(1);
});

it('liefert zu jedem Raum den Tiled-Baustein und die Tür-Sprites, offene Türen bleiben als Tür erkennbar', function () {
    $d = fixtureDungeon($this->family);
    $d->update(['state' => ['rooms' => collect($d->state['rooms'])->map(fn ($r) => ['block' => 'hall_pillars'] + $r)->all()] + $d->state]);
    torches($this->child, 20);
    Sanctum::actingAs($this->child, ['app']);
    $view = $this->getJson("/api/v1/dungeons/{$d->id}")->assertOk();

    $entrance = collect($view['rooms'])->firstWhere('id', 0);
    expect($entrance['block_url'])->toEndWith('/dungeon/blocks/hall_pillars.tmx')
        ->and($entrance['doors']['E'])->toBe(['to' => 1, 'kind' => 'open', 'sprite' => 'stoneWallArchway_W'])
        ->and($entrance['doors']['W'])->toBe(['to' => 2, 'kind' => 'locked', 'sprite' => 'stoneWallDoorClosed_E'])
        ->and($entrance['doors']['S']['sprite'])->toBe('stoneWallArchway_N')
        ->and($entrance['doors']['N'])->toBeNull()
        ->and($view['art']['tileset'])->toEndWith('/dungeon/dungeon.tsx')
        ->and($view['art']['sprites'])->toEndWith('/dungeon/sprites/{sprite}_{suffix}.png')
        ->and(file_exists(public_path('dungeon/blocks/hall_pillars.tmx')))->toBeTrue();

    $state = $d->state;
    $state['edges'][1]['kind'] = 'open';
    $state['edges'][1]['was'] = 'locked';
    $d->update(['state' => $state]);
    $doors = collect($this->getJson("/api/v1/dungeons/{$d->id}")['rooms'])->firstWhere('id', 0)['doors'];
    expect($doors['W']['sprite'])->toBe('stoneWallDoorOpen_E');
});

it('hat für jeden Baustein eine Tiled-Datei und jedes Kanten-Sprite', function () {
    $art = json_decode(file_get_contents(database_path('content/dungeons.json')), true)['art'];
    foreach (json_decode(file_get_contents(database_path('content/dungeons.json')), true)['blocks'] as $keys) {
        foreach ($keys as $key) {
            expect(file_exists(public_path("dungeon/blocks/{$key}.tmx")))->toBeTrue("Baustein {$key} fehlt");
        }
    }
    foreach ([...array_values($art['edges']), ...array_values($art['spots'])] as $sprite) {
        $suffixes = $sprite === 'switchFloorOn' ? ['E'] : array_values($art['side_suffix']);
        foreach ($suffixes as $suffix) {
            expect(file_exists(public_path("dungeon/sprites/{$sprite}_{$suffix}.png")))->toBeTrue("Sprite {$sprite}_{$suffix} fehlt");
        }
    }
});

it('würfelt Kämpfe reproduzierbar', function () {
    $runs = [];
    foreach ([1, 2] as $i) {
        $d = fixtureDungeon($this->family);
        torches($this->child, 5);
        UserStats::whereKey($this->child->id)->update(['str' => 3]);
        $d->update(['counter' => 0]);
        act($this->child, $d, 'move', ['to' => 1]);
        $runs[] = act($this->child, $d, 'fight', ['move' => 'attack'])->json('result.damage');
        $d->positions()->delete();
    }
    expect($runs[0])->toBe($runs[1]);
});

it('schickt bei 0 HP zum Eingang zurück, mit Fackel-Verlust, aber ohne Gegenstände zu verlieren', function () {
    $d = fixtureDungeon($this->family);
    Stock::addKeys($this->child->id, ['wood' => 3]);
    torches($this->child, 6);
    act($this->child, $d, 'move', ['to' => 1])->assertOk();
    DungeonPosition::where('user_id', $this->child->id)->update(['hp' => 0.5]);
    $d->update(['state' => array_replace_recursive($d->fresh()->state, ['rooms' => [1 => ['monster' => ['hp' => 500, 'max_hp' => 500]]]])]);

    $res = act($this->child, $d, 'fight', ['move' => 'attack'])->assertOk();
    expect($res['dungeon']['me']['room'])->toBe(0)
        ->and($res['dungeon']['me']['hp'])->toBe($res['dungeon']['me']['max_hp'])
        ->and(UserStats::find($this->child->id)->torches)->toBe(2)  // 6 − 1 Zug − 1 Kampf − 2 K.O.
        ->and(Stock::counts($this->child->id, ['wood'])['wood'])->toBe(3);
});

it('schaltet den Boss erst frei, wenn die Familie da ist, und rechnet Schaden auf den Familien-Boss', function () {
    $quest = app(QuestService::class)->start($this->parent, 'dust_monster', 'family');
    $d = fixtureDungeon($this->family, $quest->id);
    $state = $d->state;
    $state['rooms'][1]['monster']['hp'] = 0;
    $d->update(['state' => $state]);
    torches($this->child, 10);
    torches($this->parent, 10);

    act($this->parent, $d, 'move', ['to' => 1])->assertOk();  // zwei Mitspielende
    act($this->child, $d, 'move', ['to' => 1])->assertOk();
    act($this->child, $d, 'move', ['to' => 3])->assertOk();
    act($this->child, $d, 'fight')->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'Familie'));

    act($this->parent, $d, 'move', ['to' => 3])->assertOk()->assertJsonPath('dungeon.boss_unlocked', true);
    $hit = act($this->child, $d, 'fight', ['move' => 'attack'])->assertOk();
    expect($quest->refresh()->progress)->toBe((float) $hit['result']['boss_damage'])
        ->and($hit['result']['boss_damage'])->toBeGreaterThan(0)
        ->and(collect($hit['dungeon']['rooms'])->firstWhere('id', 3)['monster']['hp'])->toEqual(round($quest->remaining(), 1));

    // Letzter Schlag besiegt den Boss: Quest und Dungeon geschafft
    $quest->update(['progress' => $quest->goal - 0.5]);
    act($this->parent, $d, 'fight', ['move' => 'attack'])->assertOk()->assertJsonPath('dungeon.status', 'completed');
    expect($quest->refresh()->status)->toBe('completed');
});

it('nutzt Klassen: Schurke entschärft, Magier deckt auf und liest Runen, Krieger schlägt Wände ein, Tüftler zieht Hebel', function () {
    $d = fixtureDungeon($this->family);
    torches($this->child, 20);
    UserStats::whereKey($this->child->id)->update(['class_key' => 'rogue']);
    $trap = act($this->child, $d, 'move', ['to' => 4])->assertOk();
    expect($trap['result']['events'][0])->toContain('entschärft');

    UserStats::whereKey($this->child->id)->update(['class_key' => 'mage']);
    $res = act($this->child, $d, 'move', ['to' => 5])->assertOk();
    $puzzle = collect($res['dungeon']['rooms'])->firstWhere('id', 5)['puzzle'];
    expect($puzzle['hint'])->toBe('Finger!')
        ->and(collect($res['dungeon']['rooms'])->firstWhere('id', 6)['fog'])->toBeFalse(); // hinter dem Tor aufgedeckt
    act($this->child, $d, 'interact', ['answer' => '4'])->assertOk()->assertJsonPath('result.solved', false);
    act($this->child, $d, 'interact', ['answer' => '5'])->assertOk()->assertJsonPath('result.solved', true);

    act($this->child, $d, 'move', ['to' => 6])->assertStatus(422); // Tor
    act($this->child, $d, 'interact', ['to' => 1])->assertStatus(422); // Wand, aber kein Krieger
    UserStats::whereKey($this->child->id)->update(['class_key' => 'warrior']);
    act($this->child, $d, 'interact', ['to' => 1])->assertOk();
    act($this->child, $d, 'move', ['to' => 1])->assertOk();

    act($this->child, $d, 'move', ['to' => 5])->assertOk();
    act($this->child, $d, 'move', ['to' => 4])->assertOk();
    act($this->child, $d, 'move', ['to' => 7])->assertOk();
    act($this->child, $d, 'interact')->assertStatus(422); // nur Tüftler
    UserStats::whereKey($this->child->id)->update(['class_key' => 'tinkerer']);
    act($this->child, $d, 'interact')->assertOk();
    expect(collect($d->fresh()->state['edges'])->firstWhere('b', 6)['kind'])->toBe('open');
});

it('öffnet Tore auch über einen echten Taster in Home Assistant', function () {
    $d = fixtureDungeon($this->family);
    Sanctum::actingAs($this->parent, ['ha']);
    $this->postJson('/api/v1/ha/dungeon/mechanism')->assertOk()->assertJsonPath('opened', 1);
    expect(collect($d->fresh()->state['edges'])->firstWhere('b', 6)['kind'])->toBe('open');
});

it('öffnet das Solo-Gewölbe erst, wenn die Dailies geschafft sind, und schließt es mit der Schatztruhe ab', function () {
    $daily = makeTask($this->child, ['type' => 'daily', 'title' => 'Zähne putzen']);
    Sanctum::actingAs($this->child, ['app']);
    $this->postJson('/api/v1/dungeons/solo')->assertStatus(422);

    $this->postJson('/api/v1/assignments/'.assignmentOf($daily, $this->child)->id.'/score/up')->assertOk();
    $solo = $this->postJson('/api/v1/dungeons/solo')->assertOk();
    expect($solo['kind'])->toBe('solo')->and($this->postJson('/api/v1/dungeons/solo')->json('id'))->toBe($solo['id']);

    // Fertig, sobald der letzte Raum geöffnet ist: Weg vom Eingang zum Ziel freiräumen und gehen
    $d = Dungeon::find($solo['id']);
    $state = $d->state;
    $path = [$state['final']];
    while (end($path) !== 0) {
        $prev = collect($state['edges'])->map(fn ($e) => $e['a'] === end($path) ? $e['b'] : ($e['b'] === end($path) ? $e['a'] : null))
            ->filter(fn ($n) => $n !== null)->sortBy(fn ($n) => DungeonGenerator::depths(3, $state['edges'])[$n])->first();
        $path[] = $prev;
    }
    $path = array_reverse($path);
    foreach ($path as $room) {
        if (isset($state['rooms'][$room]['monster'])) {
            $state['rooms'][$room]['monster']['hp'] = 0;
        }
        if (isset($state['rooms'][$room]['trap'])) {
            $state['rooms'][$room]['trap']['sprung'] = true;
        }
    }
    $d->update(['state' => $state]);
    torches($this->child, 10);
    foreach (array_slice($path, 1) as $room) {
        act($this->child, $d, 'move', ['to' => $room])->assertOk();
    }
    act($this->child, $d, 'interact')->assertOk()->assertJsonPath('dungeon.status', 'completed');
    expect(count($path))->toBeBetween(2, 3);
});

it('zeigt anderen Familien keinen Dungeon und führt ein Zug-Log', function () {
    $d = fixtureDungeon($this->family);
    torches($this->child, 3);
    act($this->child, $d, 'move', ['to' => 4])->assertOk();

    [, $stranger] = makeFamily();
    torches($stranger, 3);
    act($stranger, $d, 'move', ['to' => 1])->assertStatus(422);
    Sanctum::actingAs($stranger, ['app']);
    $this->getJson("/api/v1/dungeons/{$d->id}")->assertNotFound();

    Sanctum::actingAs($this->parent, ['app']);
    $log = $this->getJson("/api/v1/dungeons/{$d->id}/log")->assertOk()->json('log');
    expect($log)->toHaveCount(1)->and($log[0]['user'])->toBe('Lea');
    expect($this->getJson("/api/v1/dungeons/{$d->id}/log?since={$log[0]['id']}")->json('log'))->toBeEmpty();
});
