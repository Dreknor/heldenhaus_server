<?php

use App\Domain\Features\Features;
use App\Models\FeatureUnlock;
use App\Models\Item;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['game.features.enforce' => true]);
    [$this->family, $this->parent, $this->child] = makeFamily();
});

function setLevel($user, int $level): void
{
    $user->stats()->update(['level' => $level]);
    $user->load('stats');
    Features::forget();
}

// ---------- Sperre der Web-Routen ----------

it('sperrt im Web dieselben Aktionen wie in der API, eigene Tiere wählen bleibt frei', function () {
    $gated = [
        'hero.allocate' => 'attributes', 'hero.class' => 'classes', 'hero.cast' => 'classes', 'hero.second-chance' => 'classes',
        'inventory.buy' => 'shop', 'inventory.equip' => 'shop', 'pets.hatch' => 'pets', 'pets.feed' => 'pets',
        'housing.index' => 'home', 'housing.rooms.customize' => 'home', 'housing.rooms.layout' => 'home',
        'housing.garden.action' => 'garden', 'housing.projects.contribute' => 'building',
        'housing.visit' => 'alliances', 'housing.guestbook.store' => 'alliances', 'housing.visit.guestbook.store' => 'alliances',
        'social.index' => 'alliances', 'challenges.join' => 'alliances',
        'dungeons.solo' => 'dungeons', 'crafting.craft' => 'crafting', 'crafting.storage.deposit' => 'building',
    ];
    foreach ($gated as $name => $feature) {
        $middleware = Route::getRoutes()->getByName($name)->gatherMiddleware();
        expect(collect($middleware)->contains(fn ($m) => str_starts_with($m, 'feature:') && in_array($feature, explode(',', substr($m, 8)), true)))
            ->toBeTrue("{$name} → feature:{$feature}");
    }
    // Familienrelevantes und das eigene Tier wählen haben keine Sperre
    foreach (['pets.companion', 'tasks.store', 'assignments.score', 'shop.items.redeem', 'feed.react', 'approvals.index', 'focus.start', 'inventory.use'] as $name) {
        expect(collect(Route::getRoutes()->getByName($name)->gatherMiddleware())->contains(fn ($m) => str_starts_with($m, 'feature:')))
            ->toBeFalse($name);
    }
});

it('meldet gesperrte Aktionen im Web freundlich und lässt sie nach der Freischaltung zu', function () {
    $this->child->stats()->update(['unspent_points' => 1]);

    $this->actingAs($this->child)->from('/hero')
        ->post('/hero/allocate', ['attribute' => 'str'])
        ->assertRedirect('/hero')
        ->assertSessionHasErrors(['game' => 'Das schaltest du mit Level 2 frei.']);
    expect($this->child->stats()->value('str'))->toBe(0);

    setLevel($this->child, 2);
    $this->actingAs($this->child)->from('/hero')
        ->post('/hero/allocate', ['attribute' => 'str'])
        ->assertRedirect('/hero')
        ->assertSessionHasNoErrors();
    expect($this->child->stats()->value('str'))->toBe(1);
});

// ---------- Vorschau-Seiten ----------

it('zeigt für gesperrte Seiten eine Vorschau ohne die Daten der Funktion', function () {
    $this->actingAs($this->child)->get('/housing')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('features/Locked')
            ->where('feature.key', 'home')
            ->where('feature.level', 6)
            ->where('feature.open', false)
            ->where('level', 1)
            ->missing('home')
            ->missing('furniture'));

    $this->get('/crafting')->assertInertia(fn (Assert $page) => $page->component('features/Locked')->where('feature.key', 'crafting')->missing('storage'));
    $this->get('/social')->assertInertia(fn (Assert $page) => $page->component('features/Locked')->where('feature.key', 'alliances'));
    $this->get('/dungeons')->assertInertia(fn (Assert $page) => $page->component('features/Locked')->where('feature.key', 'dungeons'));

    setLevel($this->child, 6);
    $this->get('/housing')->assertInertia(fn (Assert $page) => $page->component('Housing')
        ->where('home.garden', null)
        ->where('home.projects.active', [])
        ->where('alliedFamilies', []));
});

it('blendet in teilweise gesperrten Seiten nur die gesperrten Teile aus', function () {
    Item::create(['key' => 'sword', 'name' => 'Holzschwert', 'type' => 'equipment', 'slot' => 'weapon', 'price_gold' => 40, 'stats' => ['per' => 5]]);
    $this->actingAs($this->child)->get('/quests')
        ->assertInertia(fn (Assert $page) => $page->component('Quests')->where('soloOffers', [])->where('bestiary', []));
    $this->get('/inventory')->assertInertia(fn (Assert $page) => $page->component('Inventory')->where('shop', []));
    $this->get('/hero')->assertInertia(fn (Assert $page) => $page->component('Hero')->where('classes', []));

    setLevel($this->child, 5);
    $this->get('/quests')->assertInertia(fn (Assert $page) => $page->has('bestiary', fn ($b) => $b->etc()));
    $this->get('/inventory')->assertInertia(fn (Assert $page) => $page->where('shop', fn ($shop) => count($shop) > 0));
});

it('liefert den Freischaltstand für die Navigation mit', function () {
    $this->actingAs($this->child)->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page
            ->where('features.enforced', true)
            ->where('features.list', fn ($list) => collect($list)->firstWhere('key', 'home')['open'] === false
                && collect($list)->firstWhere('key', 'home')['level'] === 6)
            ->where('features.next.key', 'attributes'));
});

// ---------- Freischalt-Karte, Tour, Starter-Aufgabe ----------

it('zeigt die Freischalt-Karte mit Starter-Aufgabe und startet über „Zeig’s mir“ die Tour', function () {
    setLevel($this->child, 2);

    $this->actingAs($this->child)->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page
            ->where('features.intro.key', 'attributes')
            ->where('features.intro.starter_task.goal', 'Ersten Attributpunkt verteilen')
            ->where('features.starters.0.key', 'attributes'));

    $this->post('/heldenbuch/attributes/gesehen', ['tour' => true])->assertRedirect('/hero?tour=attributes');
    expect(FeatureUnlock::where('user_id', $this->child->id)->where('feature_key', 'attributes')->value('intro_seen_at'))->not->toBeNull();

    $this->get('/hero?tour=attributes')
        ->assertInertia(fn (Assert $page) => $page
            ->where('features.intro', null)
            ->where('tour.key', 'attributes')
            ->has('tour.steps', 2));

    // Touren gibt es nur für freigeschaltete Funktionen
    $this->get('/hero?tour=crafting')->assertInertia(fn (Assert $page) => $page->where('tour', null));
});

it('zeigt höchstens eine Erklärung pro Tag, auch bei mehreren Freischaltungen', function () {
    setLevel($this->child, 5);

    $this->actingAs($this->child)->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('features.intro.key', fn ($k) => $k !== null));
    $first = FeatureUnlock::where('user_id', $this->child->id)->whereNotNull('intro_shown_at')->value('feature_key');

    $this->from('/dashboard')->post("/heldenbuch/{$first}/gesehen")->assertRedirect('/dashboard');
    $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('features.intro', null));

    $this->travel(1)->days();
    Features::forget();
    $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('features.intro.key', fn ($k) => $k !== null && $k !== $first));
});

// ---------- Heldenbuch und „Alles sofort zeigen“ ----------

it('zeigt im Heldenbuch alle Funktionen, Erklärungen nur für Freigeschaltetes', function () {
    setLevel($this->child, 3);

    $this->actingAs($this->child)->get('/heldenbuch')
        ->assertInertia(fn (Assert $page) => $page->component('features/Book')
            ->has('features', count(Features::catalog()))
            ->where('canShowAll', false)
            ->where('features', function ($features) {
                $byKey = collect($features)->keyBy('key');

                return $byKey['shop']['open'] && $byKey['shop']['text'] !== null && count($byKey['shop']['tour']) >= 2
                    && ! $byKey['alliances']['open'] && $byKey['alliances']['text'] === null && $byKey['alliances']['level'] === 14;
            }));
});

it('erlaubt „Alles sofort zeigen“ im Web erst ab 13', function () {
    $this->actingAs($this->child)->from('/heldenbuch')->post('/heldenbuch/alles-zeigen', ['on' => true])
        ->assertSessionHasErrors('game');
    expect(Features::has($this->child->refresh(), 'alliances'))->toBeFalse();

    $this->actingAs($this->parent)->from('/heldenbuch')->post('/heldenbuch/alles-zeigen', ['on' => true])
        ->assertRedirect('/heldenbuch')->assertSessionHasNoErrors();
    Features::forget();
    expect(Features::has($this->parent->refresh(), 'alliances'))->toBeTrue();
    $this->get('/social')->assertInertia(fn (Assert $page) => $page->component('family/Social'));
});

// ---------- Eltern-Einstellung ----------

it('lässt Eltern im Web Funktionen je Kind früher oder später freischalten', function () {
    $this->actingAs($this->parent)->get('/family/settings')
        ->assertInertia(fn (Assert $page) => $page->where('members', fn ($members) => collect($members)
            ->firstWhere('id', $this->child->id)['featureSettings']['features'][0]['can_defer'] === true));

    $this->from('/family/settings')->put("/family/members/{$this->child->id}/features", [
        'features' => ['crafting' => 'early', 'pets' => 'defer'],
        'fast_start' => true,
    ])->assertRedirect('/family/settings')->assertSessionHasNoErrors();

    Features::forget();
    $child = $this->child->refresh();
    expect(FeatureUnlock::where('user_id', $child->id)->where('feature_key', 'crafting')->exists())->toBeTrue()
        ->and(Features::state($child, 'pets'))->toBe('deferred')
        ->and($child->fast_start_at)->not->toBeNull();

    $this->actingAs($child)->get('/crafting')->assertInertia(fn (Assert $page) => $page->component('Crafting'));
    $this->from('/inventory')->post('/pets/hatch', [])->assertSessionHasErrors(['game' => 'Das schalten deine Eltern etwas später frei.']);

    // Später freischalten nur für Kinder unter 13, und nur Eltern dürfen das
    $this->actingAs($this->parent)->from('/family/settings')
        ->put("/family/members/{$this->parent->id}/features", ['features' => ['pets' => 'defer']])
        ->assertSessionHasErrors('game');
    $this->actingAs($child)->from('/dashboard')
        ->put("/family/members/{$child->id}/features", ['features' => ['quests' => 'early']])
        ->assertSessionHasErrors('game');
    expect(Features::state($child->refresh(), 'quests'))->toBe('locked');
});
