<?php

use App\Domain\Art\PetArt;
use App\Domain\Crafting\Catalog;
use App\Domain\Events\EventCatalog;
use App\Domain\Features\Features;
use App\Domain\Rewards\RewardTemplates;
use App\Models\FeatureUnlock;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    [$this->family, $this->parent, $this->child] = makeFamily();
});

// ---------- Freischaltung nach Level (§13) ----------

it('sperrt Funktionen unter dem Level mit 403 feature_locked', function () {
    config(['game.features.enforce' => true]);
    Sanctum::actingAs($this->child, ['app']);

    $this->postJson('/api/v1/hero/allocate', ['attribute' => 'str'])->assertStatus(403)
        ->assertJsonPath('error', 'feature_locked')
        ->assertJsonPath('feature', 'attributes')
        ->assertJsonPath('unlocks_at_level', 2);
});

it('schaltet mit dem Level frei und belohnt die erste Mini-Aufgabe genau einmal', function () {
    config(['game.features.enforce' => true]);
    $this->child->stats()->update(['level' => 5]);
    Features::forget();

    $new = Features::sync($this->child->refresh());
    expect($new)->toContain('attributes', 'shop', 'pets', 'quests')
        ->not->toContain('crafting', 'alliances');

    expect(Features::starterDone($this->child, 'attributes'))->toBeTrue()
        ->and(Features::starterDone($this->child, 'attributes'))->toBeFalse()
        ->and(goldWithoutAchievements($this->child))->toBe(5.0);
});

it('lässt Eltern früher oder später freischalten, später nur bei Kindern', function () {
    config(['game.features.enforce' => true]);
    Sanctum::actingAs($this->parent, ['app']);

    $this->putJson("/api/v1/members/{$this->child->id}/features", ['features' => ['crafting' => 'early', 'pets' => 'defer']])->assertOk();
    expect(FeatureUnlock::where('user_id', $this->child->id)->where('feature_key', 'crafting')->exists())->toBeTrue()
        ->and(Features::state($this->child->refresh(), 'pets'))->toBe('deferred');

    $this->putJson("/api/v1/members/{$this->parent->id}/features", ['features' => ['pets' => 'defer']])->assertStatus(422);

    Sanctum::actingAs($this->child, ['app']);
    $this->putJson("/api/v1/members/{$this->child->id}/features", ['features' => ['quests' => 'early']])->assertStatus(422);
});

it('zeigt „alles sofort“ erst ab 13', function () {
    config(['game.features.enforce' => true]);
    Sanctum::actingAs($this->child, ['app']);
    $this->postJson('/api/v1/me/features/show-all', ['on' => true])->assertStatus(422);

    Sanctum::actingAs($this->parent, ['app']);
    $this->postJson('/api/v1/me/features/show-all', ['on' => true])->assertOk();
    expect(Features::has($this->parent->refresh(), 'alliances'))->toBeTrue();
});

// ---------- Belohnungsshop ----------

it('startet neue Familien mit Belohnungen aus den Vorlagen', function () {
    $starter = collect(RewardTemplates::all())->where('starter', true);

    expect($this->family->rewardItems()->count())->toBe($starter->count())
        ->and($this->family->rewardItems()->pluck('title')->all())->toContain('Eis essen gehen');
});

it('lässt Eltern Vorlagen übernehmen und Belohnungen verwalten', function () {
    Sanctum::actingAs($this->parent, ['app']);
    $before = $this->family->rewardItems()->count();

    $templates = $this->getJson('/api/v1/shop/templates')->assertOk()->json('templates');
    expect(collect($templates)->firstWhere('key', 'ice_cream')['adopted'])->toBeTrue()
        ->and(collect($templates)->firstWhere('key', 'cinema')['adopted'])->toBeFalse();

    // Doppelte werden übersprungen
    $this->postJson('/api/v1/shop/templates', ['keys' => ['cinema', 'ice_cream', 'cinema']])->assertCreated()->assertJsonCount(1, 'items');
    $id = $this->postJson('/api/v1/shop/items', ['title' => 'Zoo-Besuch', 'star_cost' => 30])->assertCreated()->json('id');
    $this->putJson("/api/v1/shop/items/{$id}", ['star_cost' => 25])->assertOk()->assertJsonPath('star_cost', 25);
    $this->deleteJson("/api/v1/shop/items/{$id}")->assertOk();
    expect($this->family->rewardItems()->count())->toBe($before + 2)
        ->and($this->family->rewardItems()->where('active', true)->count())->toBe($before + 1);

    Sanctum::actingAs($this->child, ['app']);
    $this->postJson('/api/v1/shop/items', ['title' => 'Alles', 'star_cost' => 1])->assertStatus(403);
    $this->getJson('/api/v1/shop')->assertOk()->assertJsonCount($before + 1, 'items');
});

// ---------- App-Konfiguration ----------

it('liefert die Firebase-Werte ohne Login nur, wenn sie vollständig sind', function () {
    $this->getJson('/api/v1/app/config')->assertOk()->assertJsonPath('firebase', null);

    config(['services.fcm.client' => ['api_key' => 'k', 'app_id' => '1:2:android:3', 'project_id' => 'heldenhaus', 'sender_id' => '42']]);
    $this->getJson('/api/v1/app/config')->assertOk()
        ->assertJsonPath('firebase.project_id', 'heldenhaus')
        ->assertJsonPath('firebase.sender_id', '42');
});

// ---------- Inhalte ----------

it('hat für jede Tierart ein Bild und jedes Lieblingsfutter passt zu einer Tierart', function () {
    $items = collect(json_decode(file_get_contents(database_path('content/items.json')), true));
    $species = $items->where('type', 'egg')->map(fn ($i) => substr($i['key'], 4));

    expect($species->count())->toBeGreaterThanOrEqual(60);
    foreach ($species as $s) {
        expect(PetArt::url("egg_{$s}"))->toBe("/images/pets/{$s}.webp");
    }
    $likes = $items->where('type', 'food')->flatMap(fn ($i) => $i['stats']['likes'] ?? []);
    expect($likes->diff($species)->values()->all())->toBe([])
        ->and($items->pluck('key')->duplicates()->all())->toBe([]);
});

it('verweist in Events, Rezepten, Freischaltungen und Belohnungen nur auf Vorhandenes', function () {
    $items = collect(json_decode(file_get_contents(database_path('content/items.json')), true))->keyBy('key');
    $events = collect(EventCatalog::all());

    foreach ($items->whereNotNull('event_key') as $item) {
        expect($events->has($item['event_key']) || $events->contains('key', $item['event_key']))->toBeTrue("{$item['key']} → {$item['event_key']}");
    }
    foreach ($events as $event) {
        foreach ($event['goal']['tiers'] ?? [] as $tier) {
            foreach (array_keys($tier['rewards']['items'] ?? []) as $key) {
                expect($items->has($key))->toBeTrue("{$event['key']} → {$key}");
            }
        }
        if (! empty($event['requires_feature'])) {
            expect(Features::get($event['requires_feature']))->not->toBeNull();
        }
        foreach ($event['recipes'] ?? [] as $recipe) {
            expect(Catalog::recipes())->toHaveKey($recipe);
        }
    }
    foreach (Features::catalog() as $key => $feature) {
        foreach (array_keys($feature['starter_items'] ?? []) as $item) {
            expect($items->has($item))->toBeTrue("{$key} → {$item}");
        }
    }
    foreach (RewardTemplates::all() as $template) {
        expect($template['star_cost'])->toBeGreaterThan(0)
            ->and(mb_strlen($template['title']))->toBeLessThanOrEqual(80)
            ->and(mb_strlen($template['icon']))->toBeLessThanOrEqual(30);
    }
});
