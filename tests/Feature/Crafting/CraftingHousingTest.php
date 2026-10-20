<?php

use App\Domain\Crafting\Catalog;
use App\Domain\Crafting\CraftingService;
use App\Domain\Crafting\StorageService;
use App\Domain\Gameplay\Attributes;
use App\Domain\Gameplay\GameException;
use App\Domain\Housing\BuildService;
use App\Domain\Housing\HomeService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\Stock;
use App\Domain\Quests\QuestService;
use App\Domain\Social\AllianceService;
use App\Models\BuildProject;
use App\Models\CraftJob;
use App\Models\FamilyAlliance;
use App\Models\FeedEntry;
use App\Models\Home;
use App\Models\Item;
use App\Models\Room;
use App\Models\TradeOffer;
use App\Models\UserItem;
use App\Models\UserStats;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->artisan('game:content-sync')->assertSuccessful();
    [$this->family, $this->parent, $this->child] = makeFamily();
    // Qualität im Test festlegen: immer „normal“
    config(['game.crafting.good' => ['base' => 0, 'step' => 0, 'max' => 0], 'game.crafting.master' => ['base' => 0, 'step' => 0, 'max' => 0]]);
});

function give($user, array $amounts): void
{
    Stock::addKeys($user->id, $amounts);
}

function have($user, string $key): int
{
    return Stock::counts($user->id, [$key])[$key];
}

function setGold($user, float $gold): void
{
    UserStats::whereKey($user->id)->update(['gold' => $gold]);
}

function alliedFamilies($a, $b): FamilyAlliance
{
    $invite = app(AllianceService::class)->invite($a);

    return app(AllianceService::class)->join($b, $invite->code);
}

function homeWith($familyId, array $rooms = [], array $stations = []): Home
{
    $home = HomeService::home($familyId);
    foreach ($rooms as $type) {
        $home->rooms()->create(['type' => $type, 'width' => 10, 'height' => 6]);
    }
    $home->update(['stations' => array_merge($home->stations ?? [], $stations)]);

    return $home->refresh();
}

it('legt das Familienhaus als Hütte mit Wohnraum, Werkbank und eigenem Zimmer je Mitglied an', function () {
    Sanctum::actingAs($this->child, ['app']);
    $res = $this->getJson('/api/v1/home')->assertOk();

    expect($res['stage'])->toBe('hut')
        ->and($res['stations']['workbench'])->toBe(1)
        ->and(collect($res['rooms'])->pluck('type')->sort()->values()->all())->toBe(['bedroom', 'bedroom', 'living'])
        ->and(collect($res['rooms'])->firstWhere('owner_id', $this->child->id)['name'])->toBe('Zimmer von Lea')
        ->and($res['projects']['available'])->not->toBeEmpty();

    // Später Hinzugekommene bekommen beim nächsten Aufruf ihr Zimmer
    joinFamily($this->family);
    expect(Room::where('type', 'bedroom')->count())->toBe(2);
    $this->getJson('/api/v1/home');
    expect(Room::where('type', 'bedroom')->count())->toBe(3);
});

it('stellt an der Werkbank her: Materialien und Gold weg, Auftrag mit Echtzeit, Abholen mit Handwerks-XP', function () {
    give($this->child, ['wood' => 5, 'nails' => 1]);
    setGold($this->child, 10);
    Sanctum::actingAs($this->child, ['app']);

    $job = $this->postJson('/api/v1/craft', ['recipe' => 'furn_table'])->assertCreated();
    expect(have($this->child, 'wood'))->toBe(1)
        ->and(have($this->child, 'nails'))->toBe(0)
        ->and(UserStats::find($this->child->id)->gold)->toBe(7.0);

    // Noch nicht fertig
    $this->postJson("/api/v1/craft/{$job['job_id']}/collect")->assertStatus(422);

    $this->travel(61)->seconds();
    $this->artisan('heldenhaus:housing-tick')->assertSuccessful();
    expect(CraftJob::find($job['job_id'])->status)->toBe('ready');

    $res = $this->postJson("/api/v1/craft/{$job['job_id']}/collect")->assertOk();
    expect($res['quantity'])->toBe(1)
        ->and(have($this->child, 'furn_table'))->toBe(1)
        ->and($res['crafting']['xp'])->toBe(5);

    $this->postJson("/api/v1/craft/{$job['job_id']}/collect")->assertStatus(422); // nur einmal
});

it('verweigert Rezepte ohne Station, Stufe, Material oder Gold', function () {
    Sanctum::actingAs($this->child, ['app']);

    // Schmiede gibt es in der Hütte noch nicht
    $this->postJson('/api/v1/craft', ['recipe' => 'armor_leather'])->assertStatus(422)->assertJsonPath('message', 'Dafür braucht ihr die Schmiede Stufe 1.');
    // Bett erst ab Handwerksstufe 2
    $this->postJson('/api/v1/craft', ['recipe' => 'furn_bed'])->assertStatus(422);
    // Material fehlt – und nichts wurde abgezogen
    give($this->child, ['wood' => 2]);
    $this->postJson('/api/v1/craft', ['recipe' => 'furn_chair'])->assertStatus(422);
    expect(have($this->child, 'wood'))->toBe(2)->and(CraftJob::count())->toBe(0);
});

it('würfelt die Qualität mit der Handwerksstufe: Meisterhaft gibt Bonuswerte bei Ausrüstung, Verbrauchsgüter gibt es öfter', function () {
    config(['game.crafting.master' => ['base' => 1, 'step' => 0, 'max' => 1]]);
    homeWith($this->family->id, ['workshop'], ['forge' => 1, 'alchemy' => 1]);
    CraftingService::crafting($this->child)->update(['level' => 3]);
    give($this->child, ['leather' => 5, 'nails' => 2, 'herbs' => 3]);
    setGold($this->child, 100);
    Sanctum::actingAs($this->child, ['app']);

    $armor = $this->postJson('/api/v1/craft', ['recipe' => 'armor_leather'])->assertCreated();
    $potion = $this->postJson('/api/v1/craft', ['recipe' => 'potion_health'])->assertCreated();
    expect($armor['quality'])->toBe('master')->and($potion['quantity'])->toBe(3);

    $this->travel(2)->hours();
    $this->postJson("/api/v1/craft/{$armor['job_id']}/collect")->assertOk();
    $this->postJson("/api/v1/craft/{$potion['job_id']}/collect")->assertOk();

    $row = UserItem::where('user_id', $this->child->id)->where('item_id', Item::firstWhere('key', 'armor_leather')->id)->first();
    expect($row->master_quantity)->toBe(1)->and(have($this->child, 'potion_health'))->toBe(3);

    $con = UserStats::find($this->child->id)->con;
    $this->postJson('/api/v1/inventory/'.$row->item_id.'/equip')->assertOk();
    $hero = $this->getJson('/api/v1/hero')->assertOk();
    expect(FeedEntry::where('type', 'craft_master')->count())->toBe(2)
        ->and(Attributes::for(UserStats::find($this->child->id))['con'])->toBe($con + 3 + 1);
});

it('schaltet Bibliotheks-Rezepte nur in der Bibliothek frei', function () {
    CraftingService::crafting($this->child)->update(['level' => 6]);
    homeWith($this->family->id, [], ['workbench' => 2]);
    give($this->child, ['parchment' => 3, 'ink' => 2]);
    Sanctum::actingAs($this->child, ['app']);

    $this->postJson('/api/v1/craft', ['recipe' => 'furn_trophy'])->assertStatus(422)->assertJsonPath('message', 'Dieses Rezept musst du zuerst in der Bibliothek lernen.');
    $this->postJson('/api/v1/recipes/learn', ['recipe' => 'furn_trophy'])->assertStatus(422);

    HomeService::home($this->family->id)->rooms()->create(['type' => 'library']);
    $this->postJson('/api/v1/recipes/learn', ['recipe' => 'furn_trophy'])->assertCreated();
    expect(have($this->child, 'parchment'))->toBe(0);

    $recipes = $this->getJson('/api/v1/recipes')->assertOk();
    expect(collect($recipes['recipes'])->firstWhere('key', 'furn_trophy')['unlocked'])->toBeTrue();
});

it('begrenzt gleichzeitige Aufträge', function () {
    config(['game.crafting.max_jobs' => 2]);
    give($this->child, ['wood' => 9]);
    setGold($this->child, 10);
    Sanctum::actingAs($this->child, ['app']);

    $this->postJson('/api/v1/craft', ['recipe' => 'furn_chair'])->assertCreated();
    $this->postJson('/api/v1/craft', ['recipe' => 'furn_chair'])->assertCreated();
    $this->postJson('/api/v1/craft', ['recipe' => 'furn_chair'])->assertStatus(422);
});

it('teilt Materialien über das Familienlager und verschenkt in der Familie', function () {
    give($this->child, ['wood' => 5, 'furn_chair' => 1]);
    Sanctum::actingAs($this->child, ['app']);
    $wood = Item::firstWhere('key', 'wood');
    $chair = Item::firstWhere('key', 'furn_chair');

    $this->postJson('/api/v1/storage/deposit', ['item_id' => $wood->id, 'quantity' => 4])->assertOk()->assertJsonPath('storage.0.quantity', 4);
    $this->postJson('/api/v1/storage/deposit', ['item_id' => $chair->id, 'quantity' => 1])->assertStatus(422); // nur Materialien

    Sanctum::actingAs($this->parent, ['app']);
    $this->postJson('/api/v1/storage/withdraw', ['item_id' => $wood->id, 'quantity' => 5])->assertStatus(422);
    $this->postJson('/api/v1/storage/withdraw', ['item_id' => $wood->id, 'quantity' => 3])->assertOk();
    expect(have($this->parent, 'wood'))->toBe(3);

    Sanctum::actingAs($this->child, ['app']);
    $this->postJson('/api/v1/gifts', ['to' => $this->parent->id, 'item_id' => $chair->id, 'quantity' => 1])->assertCreated();
    expect(have($this->parent, 'furn_chair'))->toBe(1)->and(have($this->child, 'furn_chair'))->toBe(0);

    [, $stranger] = makeFamily();
    $this->postJson('/api/v1/gifts', ['to' => $stranger->id, 'item_id' => $wood->id, 'quantity' => 1])->assertNotFound();
});

it('tauscht mit Bündnis-Familien nur Gegenstände, mit Zustimmung der Eltern bei Kindern unter 13', function () {
    [$otherFamily, $otherParent, $otherChild] = makeFamily();
    $alliance = alliedFamilies($this->parent, $otherParent);
    $wood = Item::firstWhere('key', 'wood');
    $ink = Item::firstWhere('key', 'ink');
    give($this->child, ['wood' => 5]);
    give($otherChild, ['ink' => 3]);

    Sanctum::actingAs($this->child, ['app']);
    $trade = $this->postJson('/api/v1/trade-offers', ['alliance_id' => $alliance->id, 'offer' => [$wood->id => 4], 'want' => [$ink->id => 2]])
        ->assertCreated()->assertJsonPath('status', 'awaiting_parent');
    expect(have($this->child, 'wood'))->toBe(1); // liegt in der Truhe

    // Andere Familie sieht es noch nicht
    Sanctum::actingAs($otherChild, ['app']);
    expect($this->getJson('/api/v1/trade-offers')->json('trades'))->toBeEmpty();
    $this->postJson("/api/v1/trade-offers/{$trade['id']}/accept")->assertStatus(422);

    Sanctum::actingAs($this->parent, ['app']);
    $this->postJson("/api/v1/trade-offers/{$trade['id']}/approve")->assertOk()->assertJsonPath('status', 'open');

    Sanctum::actingAs($otherChild, ['app']);
    $this->postJson("/api/v1/trade-offers/{$trade['id']}/accept")->assertOk()->assertJsonPath('status', 'awaiting_partner_parent');
    expect(have($otherChild, 'ink'))->toBe(1);

    // Die eigenen Eltern können nicht für die andere Familie zustimmen
    Sanctum::actingAs($this->parent, ['app']);
    $this->postJson("/api/v1/trade-offers/{$trade['id']}/approve")->assertStatus(422);

    Sanctum::actingAs($otherParent, ['app']);
    $this->postJson("/api/v1/trade-offers/{$trade['id']}/approve")->assertOk()->assertJsonPath('status', 'done');

    expect(have($this->child, 'ink'))->toBe(2)->and(have($otherChild, 'wood'))->toBe(4)->and(have($this->child, 'wood'))->toBe(1);
});

it('gibt Gegenstände beim Ablehnen und Zurückziehen zurück und lehnt Ausrüstung und Fremde ab', function () {
    [, $otherParent, $otherChild] = makeFamily();
    [, $strangerParent] = makeFamily();
    $alliance = alliedFamilies($this->parent, $otherParent);
    $wood = Item::firstWhere('key', 'wood');
    $ink = Item::firstWhere('key', 'ink');
    $sword = Item::firstWhere('key', 'weapon_wooden_sword');
    give($this->parent, ['wood' => 5, 'weapon_wooden_sword' => 1]);
    give($otherChild, ['ink' => 2]);

    Sanctum::actingAs($this->parent, ['app']);
    $this->postJson('/api/v1/trade-offers', ['alliance_id' => $alliance->id, 'offer' => [$sword->id => 1], 'want' => [$ink->id => 1]])->assertStatus(422);
    $trade = $this->postJson('/api/v1/trade-offers', ['alliance_id' => $alliance->id, 'offer' => [$wood->id => 5], 'want' => [$ink->id => 2]])
        ->assertCreated()->assertJsonPath('status', 'open'); // Erwachsene brauchen keine Zustimmung

    Sanctum::actingAs($strangerParent, ['app']);
    $this->postJson("/api/v1/trade-offers/{$trade['id']}/accept")->assertStatus(422);

    Sanctum::actingAs($otherChild, ['app']);
    $this->postJson("/api/v1/trade-offers/{$trade['id']}/accept")->assertOk();
    Sanctum::actingAs($otherParent, ['app']);
    $this->postJson("/api/v1/trade-offers/{$trade['id']}/reject")->assertOk()->assertJsonPath('status', 'open');
    expect(have($otherChild, 'ink'))->toBe(2);

    Sanctum::actingAs($this->parent, ['app']);
    $this->postJson("/api/v1/trade-offers/{$trade['id']}/cancel")->assertOk()->assertJsonPath('status', 'cancelled');
    expect(have($this->parent, 'wood'))->toBe(5);
});

it('speichert das Zimmer-Layout mit Versionsnummer, Grenzen und Überlappungsprüfung', function () {
    give($this->child, ['furn_bed' => 1, 'furn_chair' => 2, 'furn_painting' => 1]);
    $room = HomeService::home($this->family->id)->rooms()->where('owner_user_id', $this->child->id)->first(); // 8×5
    $bed = Item::firstWhere('key', 'furn_bed')->id;
    $chair = Item::firstWhere('key', 'furn_chair')->id;
    $painting = Item::firstWhere('key', 'furn_painting')->id;
    Sanctum::actingAs($this->child, ['app']);

    $res = $this->putJson("/api/v1/rooms/{$room->id}/layout", ['version' => 0, 'items' => [
        ['item_id' => $bed, 'x' => 0, 'y' => 0],
        ['item_id' => $chair, 'x' => 3, 'y' => 0],
        ['item_id' => $painting, 'x' => 0, 'y' => 0, 'layer' => 'wall'],    // andere Ebene, keine Überlappung
    ]])->assertOk();
    expect($res['version'])->toBe(1)->and($res['items'])->toHaveCount(3)
        ->and(have($this->child, 'furn_bed'))->toBe(0)->and(have($this->child, 'furn_chair'))->toBe(1);

    $bedId = collect($res['items'])->firstWhere('key', 'furn_bed')['id'];
    $chairId = collect($res['items'])->firstWhere('key', 'furn_chair')['id'];

    // Veraltete Version → 409 mit aktuellem Stand
    $this->putJson("/api/v1/rooms/{$room->id}/layout", ['version' => 0, 'items' => []])->assertStatus(409)->assertJsonPath('room.version', 1);
    // Überlappung: gedrehtes Bett (2×3) auf dem Stuhl
    $this->putJson("/api/v1/rooms/{$room->id}/layout", ['version' => 1, 'items' => [
        ['id' => $bedId, 'x' => 2, 'y' => 0, 'rotation' => 90],
        ['id' => $chairId, 'x' => 3, 'y' => 0],
    ]])->assertStatus(422);
    // Außerhalb des Zimmers
    $this->putJson("/api/v1/rooms/{$room->id}/layout", ['version' => 1, 'items' => [['id' => $bedId, 'x' => 6, 'y' => 0]]])->assertStatus(422);
    // Bild darf nicht auf den Boden
    $this->putJson("/api/v1/rooms/{$room->id}/layout", ['version' => 1, 'items' => [['item_id' => $painting, 'x' => 0, 'y' => 4, 'layer' => 'floor']]])->assertStatus(422);

    // Bett verschieben, Stuhl und Bild wegräumen → zurück ins Inventar; ids bleiben erhalten
    $res = $this->putJson("/api/v1/rooms/{$room->id}/layout", ['version' => 1, 'items' => [['id' => $bedId, 'x' => 5, 'y' => 2, 'rotation' => 90]]])->assertOk();
    expect($res['items'][0]['id'])->toBe($bedId)->and($res['items'][0]['rotation'])->toBe(90)
        ->and(have($this->child, 'furn_chair'))->toBe(2)->and(have($this->child, 'furn_painting'))->toBe(1);

    // Fremdes Zimmer: nur ansehen
    $parentRoom = Room::where('owner_user_id', $this->parent->id)->first();
    $this->putJson("/api/v1/rooms/{$parentRoom->id}/layout", ['version' => 0, 'items' => []])->assertStatus(422);
    $this->getJson("/api/v1/rooms/{$parentRoom->id}")->assertOk()->assertJsonPath('can_edit', false);
});

it('baut gemeinsam: Beiträge aus Inventar, Lager und Gold, dann Bauzeit und Einweihung', function () {
    Sanctum::actingAs($this->child, ['app']);
    $this->postJson('/api/v1/build-projects', ['project' => 'room_garden'])->assertStatus(422); // nur Eltern

    Sanctum::actingAs($this->parent, ['app']);
    $project = $this->postJson('/api/v1/build-projects', ['project' => 'room_garden', 'reward_text' => 'Eis essen gehen'])->assertCreated();
    $this->postJson('/api/v1/build-projects', ['project' => 'room_garden'])->assertStatus(422);

    give($this->child, ['wood' => 12, 'stone' => 5]);
    give($this->parent, ['seeds' => 5]);
    app(StorageService::class)->deposit($this->parent, Item::firstWhere('key', 'seeds'), 5);
    setGold($this->child, 50);

    Sanctum::actingAs($this->child, ['app']);
    $this->postJson("/api/v1/build-projects/{$project['id']}/contribute", ['item' => 'wood', 'amount' => 12])->assertOk()->assertJsonPath('progress.items.wood', 10);
    expect(have($this->child, 'wood'))->toBe(2); // nie mehr als nötig
    $this->postJson("/api/v1/build-projects/{$project['id']}/contribute", ['item' => 'ink', 'amount' => 1])->assertStatus(422);
    $this->postJson("/api/v1/build-projects/{$project['id']}/contribute", ['item' => 'stone', 'amount' => 5])->assertOk();
    $this->postJson("/api/v1/build-projects/{$project['id']}/contribute", ['item' => 'seeds', 'amount' => 5, 'from_storage' => true])->assertOk();
    $this->postJson("/api/v1/build-projects/{$project['id']}/contribute", ['amount' => 30])->assertOk()->assertJsonPath('status', 'building');

    expect(UserStats::find($this->child->id)->gold)->toBe(20.0)
        ->and(FeedEntry::where('type', 'build_contribution')->count())->toBe(4);

    $this->travel(25)->hours();
    $this->artisan('heldenhaus:housing-tick')->assertSuccessful();

    expect(BuildProject::find($project['id'])->status)->toBe('done')
        ->and(HomeService::hasRoom(HomeService::home($this->family->id), 'garden'))->toBeTrue()
        ->and(FeedEntry::where('type', 'build_done')->first()->text)->toContain('Eis essen gehen');
});

it('gibt beim Abbrechen alle Beiträge zurück und erweitert Stationen und Stufen', function () {
    $build = app(BuildService::class)->start($this->parent, 'room_workshop');
    give($this->child, ['wood' => 3]);
    setGold($this->child, 10);
    app(BuildService::class)->contribute($this->child, $build, 'wood', 3);
    app(BuildService::class)->contribute($this->child, $build, null, 10);
    app(BuildService::class)->cancel($this->parent, $build);
    expect(have($this->child, 'wood'))->toBe(3)->and(UserStats::find($this->child->id)->gold)->toBe(10.0);

    $build = app(BuildService::class)->start($this->parent, 'room_workshop');
    $build->update(['status' => 'building', 'finishes_at' => now()->subMinute()]);
    $stage = app(BuildService::class)->start($this->parent, 'stage_timber');
    $stage->update(['status' => 'building', 'finishes_at' => now()->subMinute()]);
    app(BuildService::class)->finishDue();

    $home = HomeService::home($this->family->id)->refresh();
    expect($home->stage)->toBe('timber')
        ->and($home->station('alchemy'))->toBe(1)->and($home->station('sewing'))->toBe(1)
        ->and($home->rooms()->where('type', 'living')->first()->width)->toBe(12)
        ->and($home->rooms()->where('type', 'bedroom')->first()->width)->toBe(8);

    expect(fn () => app(BuildService::class)->start($this->parent, 'room_workshop'))->toThrow(GameException::class, 'Das habt ihr schon gebaut.');
});

it('lässt im Garten säen, einmal am Tag gießen (auch über Home Assistant) und ernten', function () {
    homeWith($this->family->id, ['garden']);
    give($this->child, ['seeds' => 1]);
    Sanctum::actingAs($this->child, ['app']);

    $this->postJson('/api/v1/garden/1/sow', ['plant' => 'carrot'])->assertOk()->assertJsonPath('stage', 'thirsty');
    $this->postJson('/api/v1/garden/1/sow', ['plant' => 'herbs'])->assertStatus(422);
    $this->postJson('/api/v1/garden/1/water')->assertOk();
    $this->postJson('/api/v1/garden/1/water')->assertStatus(422); // heute schon
    $this->postJson('/api/v1/garden/1/harvest')->assertStatus(422);

    $this->travel(1)->days();
    Sanctum::actingAs($this->parent, ['ha']);
    $this->postJson('/api/v1/ha/garden/1/water')->assertOk()->assertJsonPath('stage', 'ready');

    Sanctum::actingAs($this->child, ['app']);
    $this->postJson('/api/v1/garden/1/harvest')->assertOk()->assertJsonPath('harvest.food_carrot', 3);
    expect(have($this->child, 'food_carrot'))->toBe(3)->and(have($this->child, 'seeds'))->toBe(1);

    $garden = $this->getJson('/api/v1/home')->json('garden');
    expect($garden['plots'])->toHaveCount(4)->and($garden['plots'][0]['stage'])->toBe('empty');
});

it('zeigt Unordnung bei überfälligen Haushalts-Familienaufgaben', function () {
    expect(HomeService::clutter($this->family->id)['level'])->toBe(0);

    foreach (range(1, 3) as $i) {
        makeTask($this->parent, ['title' => "Spülmaschine {$i}", 'scope' => 'family', 'category' => 'household', 'due_date' => now()->subDays(2)->toDateString(), 'assignees' => [$this->child->id]]);
    }
    makeTask($this->parent, ['title' => 'Mathe', 'scope' => 'family', 'category' => 'school', 'due_date' => now()->subDays(2)->toDateString(), 'assignees' => [$this->child->id]]);

    expect(HomeService::clutter($this->family->id))->toBe(['level' => 2, 'overdue' => 3]);
});

it('lässt Bündnis-Familien zu Besuch kommen und ins Gästebuch schreiben – Kinder nur mit Vorlagen', function () {
    [$otherFamily, $otherParent, $otherChild] = makeFamily();
    Sanctum::actingAs($otherChild, ['app']);
    $this->getJson("/api/v1/families/{$this->family->id}/home")->assertStatus(422);

    alliedFamilies($this->parent, $otherParent);
    $visit = $this->getJson("/api/v1/families/{$this->family->id}/home")->assertOk();
    expect($visit->json())->not->toHaveKey('storage')->not->toHaveKey('projects')
        ->and($visit['can_write_text'])->toBeFalse();

    $this->postJson("/api/v1/families/{$this->family->id}/guestbook", ['kind' => 'text', 'body' => 'Hallo!'])->assertStatus(422);
    $this->postJson("/api/v1/families/{$this->family->id}/guestbook", ['kind' => 'preset', 'body' => 'well_done'])->assertCreated()
        ->assertJsonPath('text', 'Gut gemacht! 👍');

    Sanctum::actingAs($otherParent, ['app']);
    $entry = $this->postJson("/api/v1/families/{$this->family->id}/guestbook", ['kind' => 'text', 'body' => 'Schönes Haus!'])->assertCreated();

    Sanctum::actingAs($this->parent, ['app']);
    expect($this->getJson('/api/v1/home')->json('guestbook'))->toHaveCount(2);
    $this->deleteJson("/api/v1/guestbook/{$entry['id']}")->assertOk();

    Sanctum::actingAs($otherChild, ['app']);
    $room = Room::where('owner_user_id', $this->child->id)->first();
    $this->getJson("/api/v1/rooms/{$room->id}")->assertOk()->assertJsonPath('can_edit', false);
    $this->putJson("/api/v1/rooms/{$room->id}/layout", ['version' => 0, 'items' => []])->assertStatus(422);
});

it('lässt Haustiere mit Stall schneller wachsen', function () {
    $egg = Item::firstWhere('key', 'egg_wolf');
    $potion = Item::firstWhere('key', 'potion_fire');
    $apple = Item::firstWhere('key', 'food_apple');
    give($this->child, ['egg_wolf' => 1, 'potion_fire' => 1, 'food_apple' => 2]);
    $pet = app(InventoryService::class)->hatch($this->child, $egg, $potion);

    app(InventoryService::class)->feed($this->child, $pet, $apple);
    expect($pet->refresh()->growth)->toBe(config('game.pet_growth_per_food'));

    homeWith($this->family->id, ['stable']);
    app(InventoryService::class)->feed($this->child, $pet, $apple);
    expect($pet->refresh()->growth)->toBe(2 * config('game.pet_growth_per_food') + config('game.housing.stable_feed_bonus'));
});

it('enthält nur Rezepte, Bauprojekte und Pflanzen mit vorhandenen Gegenständen', function () {
    $keys = Item::pluck('key')->all();
    foreach (Catalog::recipes() as $r) {
        expect(array_diff(array_merge([$r['output']], array_keys($r['inputs']), array_keys($r['learn'] ?? [])), $keys))->toBeEmpty();
    }
    foreach (Catalog::projects() as $p) {
        expect(array_diff(array_keys($p['items']), $keys))->toBeEmpty();
    }
    foreach (Catalog::plants() as $p) {
        expect(array_diff(array_merge(array_keys($p['cost']), array_keys($p['yield'])), $keys))->toBeEmpty();
    }
    expect(TradeOffer::count())->toBe(0);
});

it('begrenzt Inventarplätze, der Lagerraum gibt mehr', function () {
    config(['game.housing.inventory_slots' => 2, 'game.housing.storage_room_slots' => 1]);
    give($this->child, ['wood' => 5, 'stone' => 1]);
    setGold($this->child, 10);
    Sanctum::actingAs($this->child, ['app']);

    $this->postJson('/api/v1/craft', ['recipe' => 'furn_chair'])->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'Inventar ist voll'));
    homeWith($this->family->id, ['storage']);
    $this->postJson('/api/v1/craft', ['recipe' => 'furn_chair'])->assertCreated();
    expect($this->getJson('/api/v1/inventory')->json('slots'))->toBe(['used' => 2, 'max' => 3]);
});

it('stellt Familien-Gegenstände her: Boss-Falle, Feuerwerk und Serien-Amulett (1× pro Woche)', function () {
    $quest = app(QuestService::class)->start($this->parent, 'dust_monster', 'family');
    give($this->child, ['boss_trap' => 1, 'fireworks' => 1, 'amulet_streak' => 2]);
    Sanctum::actingAs($this->child, ['app']);

    $this->postJson('/api/v1/inventory/'.Item::firstWhere('key', 'boss_trap')->id.'/use')->assertOk();
    expect($quest->refresh()->progress)->toBe(15.0);

    $this->postJson('/api/v1/inventory/'.Item::firstWhere('key', 'fireworks')->id.'/use')->assertOk();
    expect(FeedEntry::where('type', 'fireworks')->exists())->toBeTrue();

    $amulet = Item::firstWhere('key', 'amulet_streak')->id;
    $this->postJson("/api/v1/inventory/{$amulet}/use")->assertOk();
    $this->postJson("/api/v1/inventory/{$amulet}/use")->assertStatus(422);
    expect(UserStats::find($this->child->id)->streak_shields)->toBe(1)->and(have($this->child, 'amulet_streak'))->toBe(1);
});

it('heilt nachts etwas mehr mit Bett im eigenen Zimmer und zeigt die Trophäenhalle', function () {
    give($this->child, ['furn_bed' => 1]);
    $room = HomeService::home($this->family->id)->rooms()->where('owner_user_id', $this->child->id)->first();
    app(HomeService::class)->saveLayout($this->child, $room, 0, [['item_id' => Item::firstWhere('key', 'furn_bed')->id, 'x' => 0, 'y' => 0]]);
    expect(HomeService::hasBed($this->child))->toBeTrue()->and(HomeService::hasBed($this->parent))->toBeFalse();

    homeWith($this->family->id, ['trophy']);
    Sanctum::actingAs($this->child, ['app']);
    expect($this->getJson('/api/v1/home')->json('trophies'))->toHaveKeys(['bosses', 'master_items', 'achievements']);
});
