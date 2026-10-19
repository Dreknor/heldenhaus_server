<?php

use App\Domain\Gameplay\GameException;
use App\Domain\Gameplay\RunDayCron;
use App\Domain\Gameplay\ScoreTask;
use App\Domain\Quests\QuestService;
use App\Models\FeedEntry;
use App\Models\Item;
use App\Models\Quest;
use App\Models\UserItem;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-19 10:00', 'Europe/Berlin'));
    [$this->family, $this->parent, $this->child] = makeFamily();
    $this->quests = app(QuestService::class);
    $this->score = app(ScoreTask::class);
});

it('macht Boss-Schaden, nimmt ihn beim Rückgängigmachen zurück und belohnt alle beim Sieg', function () {
    $cake = Item::create(['key' => 'food_cake', 'name' => 'Kuchen', 'type' => 'food']);
    $quest = $this->quests->start($this->parent, 'dust_monster', 'family', 'Pizza-Abend');
    $task = makeTask($this->child, ['difficulty' => 2, 'category' => 'school']);

    $this->score->__invoke(assignmentOf($task, $this->child), 'up');
    expect($quest->refresh()->remaining())->toBe(142.0);

    $this->score->__invoke(assignmentOf($task, $this->child), 'down');
    expect($quest->refresh()->remaining())->toBe(150.0);

    $quest->update(['progress' => 145]);
    $this->score->__invoke(assignmentOf($task, $this->child), 'up');

    expect($quest->refresh()->status)->toBe('completed')
        ->and($this->parent->stats()->first()->gold)->toBe(20.0)
        ->and($this->parent->stats()->first()->xp)->toBe(30)
        ->and(UserItem::where('item_id', $cake->id)->sum('quantity'))->toEqual(2)
        ->and(FeedEntry::where('type', 'quest_completed')->value('text'))->toContain('Pizza-Abend');
});

it('Schwäche macht 50 % mehr Schaden, Resistenz halbiert ihn', function () {
    $this->child->stats()->update(['level' => 3]);
    $this->quests->start($this->child, 'procrastination_troll', 'solo'); // schwach: Schule, resistent: Kreativ

    $school = $this->score->__invoke(assignmentOf(makeTask($this->child, ['category' => 'school']), $this->child), 'up');
    $creative = $this->score->__invoke(assignmentOf(makeTask($this->child, ['category' => 'creative']), $this->child), 'up');
    $household = $this->score->__invoke(assignmentOf(makeTask($this->child, ['category' => 'household']), $this->child), 'up');

    expect($school->completion->quest_progress)->toBe(6.0)
        ->and($creative->completion->quest_progress)->toBe(2.0)
        ->and($household->completion->quest_progress)->toBe(4.0);
});

it('Sammel-Quests zählen nur passende Kategorien, schwere Aufgaben doppelt', function () {
    $quest = $this->quests->start($this->child, 'collect_herbs', 'solo'); // Sport & Natur

    $this->score->__invoke(assignmentOf(makeTask($this->child, ['category' => 'sport', 'difficulty' => 2]), $this->child), 'up');
    $this->score->__invoke(assignmentOf(makeTask($this->child, ['category' => 'nature']), $this->child), 'up');
    $this->score->__invoke(assignmentOf(makeTask($this->child, ['category' => 'school']), $this->child), 'up');

    expect($quest->refresh()->progress)->toBe(3.0)->and($quest->goal)->toBe(10.0);
});

it('Solo- und Familien-Quest laufen gleichzeitig und bekommen beide Fortschritt', function () {
    $family = $this->quests->start($this->parent, 'chaos_dragon', 'family');
    $solo = $this->quests->start($this->child, 'collect_stars', 'solo');

    $this->score->__invoke(assignmentOf(makeTask($this->child), $this->child), 'up');

    expect($family->refresh()->progress)->toBe(4.0)->and($solo->refresh()->progress)->toBe(1.0);

    // Die Solo-Quest der Eltern bekommt nichts von den Aufgaben des Kindes
    $parentSolo = $this->quests->start($this->parent, 'collect_stars', 'solo');
    $this->score->__invoke(assignmentOf(makeTask($this->child), $this->child), 'up');
    expect($parentSolo->refresh()->progress)->toBe(0.0);
});

it('verpasste Dailies heilen Bosse – mit Wut stärker', function () {
    $this->parent->stats()->update(['level' => 3]);
    $quest = $this->quests->start($this->parent, 'laundry_hydra', 'family'); // Wut ×2
    $quest->update(['progress' => 50]);
    ($c = app(RunDayCron::class))($this->child);
    makeTask($this->child, ['type' => 'daily']);

    $this->travelTo(CarbonImmutable::parse('2026-10-20 08:00', 'Europe/Berlin'));
    $c($this->child->refresh());

    expect($quest->refresh()->progress)->toBe(44.0); // 1 verpasst × 3 × 2
});

it('prüft Regeln beim Starten', function () {
    expect(fn () => $this->quests->start($this->child, 'chaos_dragon', 'family'))->toThrow(GameException::class, 'Eltern')
        ->and(fn () => $this->quests->start($this->child, 'chaos_dragon', 'solo'))->toThrow(GameException::class, 'gemeinsam')
        ->and(fn () => $this->quests->start($this->child, 'screen_kraken', 'solo'))->toThrow(GameException::class, 'Level 5');

    $this->quests->start($this->child, 'boredom_ghost', 'solo');
    expect(fn () => $this->quests->start($this->child, 'collect_stars', 'solo'))->toThrow(GameException::class, 'schon');
});

it('Quests laufen ab und können abgebrochen werden', function () {
    $solo = $this->quests->start($this->child, 'boredom_ghost', 'solo');
    $this->quests->abandon($this->child, $solo);
    expect($solo->refresh()->status)->toBe('abandoned');

    $family = $this->quests->start($this->parent, 'dust_monster', 'family');
    expect(fn () => $this->quests->abandon($this->child, $family))->toThrow(GameException::class);

    $this->travel(8)->days();
    expect($this->quests->activeFamily($this->family->id))->toBeNull()->and($family->refresh()->status)->toBe('expired');
});

it('Familien-Quests werden mit der Familiengröße schwerer', function () {
    joinFamily($this->family);
    joinFamily($this->family);

    expect($this->quests->start($this->parent, 'dust_monster', 'family')->goal)->toBe(300.0); // 4 Spieler → ×2
});

it('die Quest-Tafel wechselt und bietet nicht wieder die zuletzt gespielte Quest an', function () {
    $offers = collect($this->quests->offers($this->child, 'solo'))->pluck('key');
    expect($offers)->toHaveCount(3)
        ->and($offers->all())->toBe(collect($this->quests->offers($this->child, 'solo'))->pluck('key')->all()); // stabil innerhalb der Woche

    $played = $offers->first();
    $quest = $this->quests->start($this->child, $played, 'solo');
    $this->quests->abandon($this->child, $quest);

    expect(collect($this->quests->offers($this->child, 'solo'))->pluck('key'))->not->toContain($played);
});

it('bietet die Quest-Seite und die API an', function () {
    $this->actingAs($this->parent)->post('/quests', ['quest' => 'chaos_dragon', 'scope' => 'family', 'reward_text' => 'Kino'])->assertSessionHasNoErrors();
    expect(Quest::first()->reward_text)->toBe('Kino');

    $this->actingAs($this->child)->post('/quests', ['quest' => 'collect_stars', 'scope' => 'family'])->assertSessionHasErrors('game');
    $this->get('/quests')->assertInertia(fn ($page) => $page->component('Quests')->where('family.name', 'Chaos-Drache')->has('soloOffers', 3));
    $this->get('/dashboard')->assertInertia(fn ($page) => $page->where('quests.family.key', 'chaos_dragon'));

    Sanctum::actingAs($this->child, ['app']);
    $this->postJson('/api/v1/quests', ['quest' => 'boredom_ghost', 'scope' => 'solo'])->assertCreated()->assertJsonPath('type', 'boss');
    $this->getJson('/api/v1/quests')->assertOk()->assertJsonPath('solo.name', 'Langeweile-Gespenst');
});
