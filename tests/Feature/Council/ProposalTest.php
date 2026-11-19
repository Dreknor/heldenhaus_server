<?php

use App\Domain\Council\ProposalService;
use App\Domain\Gameplay\GameException;
use App\Models\FeedEntry;
use App\Models\Proposal;
use App\Models\RewardItem;
use App\Models\Task;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    [$this->family, $this->parent, $this->child] = makeFamily();
    $this->proposals = app(ProposalService::class);
});

it('Belohnungsvorschlag → Annahme mit geändertem Preis landet im Sterne-Shop', function () {
    Sanctum::actingAs($this->child, ['app']);
    $id = $this->postJson('/api/v1/proposals', ['kind' => 'reward', 'template_key' => 'cinema', 'stars' => 20])
        ->assertCreated()->assertJsonPath('status', 'open')->assertJsonPath('title', 'Kinobesuch')->json('id');

    Sanctum::actingAs($this->parent, ['app']);
    $this->getJson('/api/v1/proposals')->assertOk()->assertJsonPath('proposals.0.id', $id);
    $this->postJson("/api/v1/proposals/{$id}/accept", ['stars' => 30])->assertOk()->assertJsonPath('status', 'accepted');

    $item = RewardItem::where('title', 'Kinobesuch')->latest('id')->first();
    expect($item->star_cost)->toBe(30)->and($item->active)->toBeTrue()
        ->and(Proposal::find($id)->reward_item_id)->toBe($item->id)
        ->and(FeedEntry::where('type', 'proposal_accepted')->value('text'))->toContain('Kinobesuch');

    // Nochmal entscheiden geht nicht
    $this->postJson("/api/v1/proposals/{$id}/decline", ['reason' => 'Doch nicht'])->assertStatus(422);
});

it('Aufgabenvorschlag für die Börse und für Eltern wird als Familienaufgabe angelegt', function () {
    $pool = $this->proposals->propose($this->child, ['kind' => 'task', 'target' => 'pool', 'template_key' => 'water_plants']);
    $forDad = $this->proposals->propose($this->child, ['kind' => 'task', 'target' => 'parent', 'for_user_id' => $this->parent->id, 'template_key' => 'bike_repair']);

    $this->proposals->accept($this->parent, $pool, ['stars' => 3]);
    $this->proposals->accept($this->parent, $forDad);

    $poolTask = Task::find($pool->refresh()->task_id);
    $dadTask = Task::find($forDad->refresh()->task_id);
    expect($poolTask->isPool())->toBeTrue()->and($poolTask->star_reward)->toBe(3)
        ->and($dadTask->title)->toBe('Fahrrad reparieren')
        ->and($dadTask->assignments()->pluck('user_id')->all())->toBe([$this->parent->id]);
});

it('Ablehnung braucht eine freundliche Begründung und landet nicht im Feed', function () {
    $p = $this->proposals->propose($this->child, ['kind' => 'reward', 'template_key' => 'sleepover']);

    expect(fn () => $this->proposals->decline($this->parent, $p, ''))->toThrow(GameException::class);

    $this->actingAs($this->parent)->post("/proposals/{$p->id}/decline", ['reason' => 'Lass uns im Familienrat darüber reden.'])
        ->assertSessionHasNoErrors();

    expect($p->refresh()->status)->toBe('declined')->and($p->reason)->toContain('Familienrat')
        ->and(RewardItem::where('title', 'Übernachtungsparty')->exists())->toBeFalse()
        ->and(FeedEntry::where('type', 'like', 'proposal%')->exists())->toBeFalse();
});

it('Kinder unter 13 wählen Vorlagen, Freitext nur mit Elternfreigabe; Teens schreiben frei', function () {
    expect(fn () => $this->proposals->propose($this->child, ['kind' => 'reward', 'title' => 'Ein Pony']))
        ->toThrow(GameException::class, 'Liste');

    $this->family->update(['settings' => ['council' => ['free_text' => true]]]);
    expect($this->proposals->propose($this->child->refresh(), ['kind' => 'reward', 'title' => 'Ein Pony', 'stars' => 99])->title)->toBe('Ein Pony');

    $teen = joinFamily($this->family, attributes: ['birth_year' => now()->year - 14]);
    $this->family->update(['settings' => []]);
    expect($this->proposals->propose($teen->refresh(), ['kind' => 'task', 'target' => 'pool', 'title' => 'Auto waschen', 'stars' => 5])->status)->toBe('open');
});

it('Rechte: Eltern schlagen nicht vor, Kinder entscheiden nicht, fremde Familien sehen nichts', function () {
    $p = $this->proposals->propose($this->child, ['kind' => 'reward', 'template_key' => 'pizza_night']);

    expect(fn () => $this->proposals->propose($this->parent, ['kind' => 'reward', 'title' => 'X']))->toThrow(GameException::class);

    Sanctum::actingAs($this->child, ['app']);
    $this->postJson("/api/v1/proposals/{$p->id}/accept")->assertStatus(422);

    [, $otherParent] = makeFamily();
    Sanctum::actingAs($otherParent, ['app']);
    $this->postJson("/api/v1/proposals/{$p->id}/accept")->assertStatus(422);
    expect($this->getJson('/api/v1/proposals')->json('proposals'))->toBe([]);
});

it('Web-Seite der Vorschläge zeigt Kindern nur ihre eigenen', function () {
    $sibling = joinFamily($this->family, attributes: ['birth_year' => now()->year - 11]);
    $this->proposals->propose($sibling, ['kind' => 'reward', 'template_key' => 'ice_cream']);
    $this->proposals->propose($this->child, ['kind' => 'reward', 'template_key' => 'cinema']);

    $this->actingAs($this->child)->get('/proposals')->assertOk()
        ->assertInertia(fn ($page) => $page->component('Proposals')->has('proposals', 1)->where('canPropose', true));
    $this->actingAs($this->parent)->get('/proposals')->assertOk()
        ->assertInertia(fn ($page) => $page->has('proposals', 2)->where('isParent', true));
});
