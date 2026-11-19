<?php

use App\Domain\Events\EventCalendar;
use App\Domain\Events\EventCatalog;
use App\Domain\Events\EventEngine;
use App\Domain\Events\EventTrigger;
use App\Models\EventOverride;
use App\Models\FamilyEvent;
use App\Models\FunEventInstance;
use App\Models\User;

beforeEach(function () {
    [$this->family, $this->parent, $this->child] = makeFamily();
});

function openFunEvent(User $user, string $key, array $attributes = []): FunEventInstance
{
    return FunEventInstance::create(array_merge([
        'family_id' => $user->family_id,
        'user_id' => $user->id,
        'key' => $key,
        'seed' => sha1($key.microtime()),
        'status' => 'open',
        'data' => [],
        'visible_from' => now(),
        'expires_at' => now()->addMinutes(30),
    ], $attributes));
}

// ---------- Banner & Deko ----------

it('liefert laufende Events mit Stufen und Deko in den Board-Props', function () {
    app(EventTrigger::class)->consider($this->family->id, 'pumpkin_night', ['manual' => true]);

    $this->actingAs($this->child)->get(route('dashboard'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('Dashboard')
            ->has('events', 1)
            ->where('events.0.key', 'pumpkin_night')
            ->where('events.0.decor.colors', ['#f97316', '#581c87'])
            ->has('events.0.tiers')
            ->has('events.0.time_progress')
            ->has('funEvents'));
});

it('zeigt ohne laufendes Event keinen Banner', function () {
    $this->actingAs($this->child)->get(route('dashboard'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('events', []));
});

it('gibt die Event-Deko an das Familienhaus weiter', function () {
    app(EventTrigger::class)->consider($this->family->id, 'first_snow', ['manual' => true]);

    $this->actingAs($this->child)->get(route('housing.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('Housing')->where('events.0.key', 'first_snow')->has('funEvents'));
});

// ---------- Zufallsereignisse ----------

it('würfelt Zufallsereignisse im Web und hält das Tageslimit ein', function () {
    config(['game.fun_events.per_family_daily' => 1]);
    openFunEvent($this->child, 'fortune_cookie');

    $this->actingAs($this->child)->post(route('web.fun-events.roll'), ['context' => 'campfire'])
        ->assertRedirect()->assertSessionHas('success', 'Hier ist gerade alles ruhig. Schau später wieder vorbei.');
    expect(FunEventInstance::count())->toBe(1);

    $this->post(route('web.fun-events.roll'), ['context' => 'keller'])->assertSessionHasErrors('context');
});

it('zeigt Zufallsereignisse und die Tagesnutzung auf der Event-Seite', function () {
    openFunEvent($this->child, 'magpie');

    $this->actingAs($this->child)->get(route('web.events.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->has('funEvents', 1)->where('funEvents.0.key', 'magpie')
            ->where('funUsage.user', 1)->where('startable', [])->where('canManage', false));
});

it('löst ein Zufallsereignis im Web ein, genau einmal', function () {
    $instance = openFunEvent($this->child, 'magpie');
    $gold = $this->child->stats()->first()->gold;

    $this->actingAs($this->child)->post(route('web.fun-events.interact', ['instance' => $instance->id]))
        ->assertRedirect()->assertSessionHas('success', fn ($m) => str_contains($m, '+3 Gold'));
    expect($instance->refresh()->status)->toBe('claimed')
        ->and($this->child->stats()->first()->gold)->toEqual($gold + 3);

    $this->post(route('web.fun-events.interact', ['instance' => $instance->id]))->assertSessionHasErrors('game');
});

it('lässt bei „Wer zuerst“ genau eine Person gewinnen', function () {
    $instance = openFunEvent($this->child, 'runaway_chicken', ['user_id' => null]);

    $this->actingAs($this->child)->post(route('web.fun-events.interact', ['instance' => $instance->id]))->assertSessionHas('success');
    $this->actingAs($this->parent)->post(route('web.fun-events.interact', ['instance' => $instance->id]))
        ->assertSessionHas('success', fn ($m) => str_contains($m, 'schneller'));
    expect($instance->refresh()->claimed_by)->toBe($this->child->id);
});

it('verweigert fremde Zufallsereignisse', function () {
    $instance = openFunEvent($this->child, 'magpie');

    $this->actingAs($this->parent)->post(route('web.fun-events.interact', ['instance' => $instance->id]))->assertSessionHasErrors('game');
    expect($instance->refresh()->status)->toBe('open');
});

// ---------- Gasthaus ----------

it('lässt Personen im Web ins Gasthaus gehen und zurückkommen', function () {
    $this->actingAs($this->child)->post(route('web.inn.enter'))->assertRedirect()->assertSessionHas('success');
    expect($this->child->stats()->first()->sleeping)->toBeTrue();

    $this->post(route('web.inn.enter'))->assertSessionHasErrors('game');
    $this->delete(route('web.inn.leave'))->assertRedirect()->assertSessionHas('success');
    expect($this->child->stats()->first()->sleeping)->toBeFalse();
});

// ---------- Event von Hand starten ----------

it('lässt nur Eltern im Web ein Event starten', function () {
    $this->actingAs($this->child)->post(route('web.events.start'), ['key' => 'heatwave'])->assertForbidden();
    expect(EventEngine::isRunning($this->family->id, 'heatwave'))->toBeFalse();

    $response = $this->actingAs($this->parent)->post(route('web.events.start'), ['key' => 'heatwave']);
    $event = FamilyEvent::where('family_id', $this->family->id)->where('event_key', 'heatwave')->firstOrFail();
    $response->assertRedirect(route('web.events.show', ['event' => $event->id]))->assertSessionHas('success');
    expect(EventEngine::isRunning($this->family->id, 'heatwave'))->toBeTrue();

    $this->post(route('web.events.start'), ['key' => 'heatwave'])->assertSessionHasErrors('game');
});

it('verlangt beim Geburtstag ein Familienmitglied und bietet Eltern die Startliste an', function () {
    $this->actingAs($this->parent)->get(route('web.events.index'))
        ->assertInertia(fn ($page) => $page->where('canManage', true)->has('members', 2)
            ->where('startable', fn ($list) => collect($list)->firstWhere('key', 'birthday')['needs_subject'] === true));

    $this->post(route('web.events.start'), ['key' => 'birthday'])->assertSessionHasErrors('game');
    $this->post(route('web.events.start'), ['key' => 'birthday', 'subject_user_id' => $this->child->id])->assertSessionHas('success');
    expect(FamilyEvent::where('event_key', 'birthday')->value('subject_user_id'))->toBe($this->child->id);
});

// ---------- Admin-Editor ----------

it('lässt nur Admins in den Event-Editor', function () {
    $this->actingAs($this->parent)->get(route('admin.events.index'))->assertForbidden();
    $this->put(route('admin.events.update', ['kind' => 'event', 'key' => 'heatwave']), ['definition' => ['name' => 'X']])->assertForbidden();
    $this->post(route('admin.events.start'), ['family_id' => $this->family->id, 'key' => 'heatwave'])->assertForbidden();
    $this->delete(route('admin.events.destroy', ['kind' => 'event', 'key' => 'heatwave']))->assertForbidden();

    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin)->get(route('admin.events.index', ['region' => 'DE-BY']))->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/Events')
            ->has('events', count(EventCatalog::baseAll()))
            ->has('fun', count(EventCatalog::baseFun()))
            ->where('region', 'DE-BY')
            ->has('calendar', 2)
            ->where('calendar.0.easter', EventCalendar::easter((int) now('Europe/Berlin')->year)->toDateString()));
});

it('speichert Admin-Änderungen als Override, ohne die Datei zu ändern', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $file = file_get_contents(database_path('content/events.json'));
    $definition = EventCatalog::get('heatwave');
    $definition['name'] = 'Große Hitze';
    $definition['duration_days'] = 5;

    $this->actingAs($admin)->put(route('admin.events.update', ['kind' => 'event', 'key' => 'heatwave']), ['definition' => $definition])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect(EventOverride::where('key', 'heatwave')->value('data'))->toBe(['name' => 'Große Hitze', 'duration_days' => 5])
        ->and(file_get_contents(database_path('content/events.json')))->toBe($file);
    EventCatalog::flush();
    expect(EventCatalog::get('heatwave')['name'])->toBe('Große Hitze')
        ->and(EventCatalog::baseAll()['heatwave']['name'])->toBe('Hitzewelle');

    // Das geänderte Event wirkt im Spiel
    $event = app(EventTrigger::class)->consider($this->family->id, 'heatwave', ['manual' => true]);
    expect((int) round($event->starts_at->diffInDays($event->ends_at)))->toBe(5);

    $this->delete(route('admin.events.destroy', ['kind' => 'event', 'key' => 'heatwave']))->assertRedirect();
    EventCatalog::flush();
    expect(EventOverride::count())->toBe(0)->and(EventCatalog::get('heatwave')['name'])->toBe('Hitzewelle');
});

it('prüft Admin-Änderungen: keine Sterne, feste Kategorie, winzige Belohnung', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $event = EventCatalog::get('heatwave');
    $this->actingAs($admin)->put(route('admin.events.update', ['kind' => 'event', 'key' => 'heatwave']), ['definition' => ['category' => 'calendar'] + $event])
        ->assertSessionHasErrors('definition.category');

    $fun = EventCatalog::fun()['magpie'];
    $fun['reward'] = ['gold' => 3, 'stars' => 1];
    $this->put(route('admin.events.update', ['kind' => 'fun', 'key' => 'magpie']), ['definition' => $fun])->assertSessionHasErrors('definition.reward.stars');

    $fun['reward'] = ['gold' => 2];
    $fun['chance'] = 0.1;
    $this->put(route('admin.events.update', ['kind' => 'fun', 'key' => 'magpie']), ['definition' => $fun])->assertSessionHasNoErrors();
    EventCatalog::flush();
    expect(EventCatalog::fun()['magpie']['reward'])->toBe(['gold' => 2]);

    $this->put(route('admin.events.update', ['kind' => 'event', 'key' => 'gibts_nicht']), ['definition' => $event])->assertNotFound();
});

it('startet als Admin ein Event für eine Familie', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)->post(route('admin.events.start'), ['family_id' => $this->family->id, 'key' => 'golden_week'])
        ->assertRedirect()->assertSessionHas('success');
    expect(EventEngine::isRunning($this->family->id, 'golden_week'))->toBeTrue();

    $this->post(route('admin.events.start'), ['family_id' => $this->family->id, 'key' => 'birthday'])->assertSessionHasErrors('subject_user_id');
    $this->post(route('admin.events.start'), ['family_id' => $this->family->id, 'key' => 'birthday', 'subject_user_id' => $this->child->id])
        ->assertSessionHas('success');
});
