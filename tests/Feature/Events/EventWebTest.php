<?php

use App\Domain\Events\EventEngine;
use App\Domain\Events\EventTrigger;
use App\Http\Controllers\EventController;
use App\Models\FamilyEventSetting;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Response as InertiaResponse;

beforeEach(function () {
    [$this->family, $this->parent, $this->child] = makeFamily();
});

function eventWebPage(InertiaResponse $response, Request $request): array
{
    $request->headers->set('X-Inertia', 'true');

    return $response->toResponse($request)->getData(true);
}

function eventWebRequest(User $user): Request
{
    $request = Request::create('/events');
    $request->setUserResolver(fn () => $user);

    return $request;
}

it('renders the current event board and visible event details', function () {
    $event = app(EventTrigger::class)->consider($this->family->id, 'heatwave', ['manual' => true]);
    $controller = app(EventController::class);

    $board = eventWebPage($controller->webIndex(eventWebRequest($this->child)), eventWebRequest($this->child));
    expect($board['component'])->toBe('events/Index')
        ->and($board['props']['running'])->toHaveCount(1)
        ->and($board['props']['canManage'])->toBeFalse();

    $detailRequest = eventWebRequest($this->child);
    $detail = eventWebPage($controller->webShow($detailRequest, $event), $detailRequest);
    expect($detail['component'])->toBe('events/Show')
        ->and($detail['props']['key'])->toBe('heatwave')
        ->and($detail['props']['own'])->toHaveKey('points');
});

it('renders completed events in the history page', function () {
    $event = app(EventTrigger::class)->consider($this->family->id, 'heatwave', ['manual' => true]);
    app(EventEngine::class)->finish($event);
    $request = eventWebRequest($this->child);

    $history = eventWebPage(app(EventController::class)->webHistory($request), $request);

    expect($history['component'])->toBe('events/History')
        ->and($history['props']['events'][0]['key'])->toBe('heatwave');
});

it('renders and saves event settings for parents', function () {
    $request = eventWebRequest($this->parent);
    $settings = eventWebPage(app(EventController::class)->webSettings($request), $request);

    expect($settings['component'])->toBe('events/Settings')
        ->and($settings['props'])->toHaveKeys(['events', 'fun', 'calendar', 'school_holidays']);

    $this->actingAs($this->parent)->put(route('web.events.settings.update'), ['fun_events' => false])->assertRedirect();

    expect(FamilyEventSetting::for($this->family->id)->fun_events)->toBeFalse();
});

it('serves event pages through the authenticated web routes', function () {
    $event = app(EventTrigger::class)->consider($this->family->id, 'heatwave', ['manual' => true]);

    $this->actingAs($this->child)->get(route('web.events.index'))
        ->assertOk()->assertInertia(fn ($page) => $page->component('events/Index'));
    $this->get(route('web.events.show', ['event' => $event->id]))
        ->assertOk()->assertInertia(fn ($page) => $page->component('events/Show')->where('key', 'heatwave'));
    $this->actingAs($this->parent)->get(route('web.events.settings'))
        ->assertOk()->assertInertia(fn ($page) => $page->component('events/Settings'));
});

it('lets children assign a parent an event task from the web event page', function () {
    $event = app(EventTrigger::class)->consider($this->family->id, 'role_swap', ['manual' => true]);

    $this->actingAs($this->child)->get(route('web.events.show', ['event' => $event->id]))
        ->assertOk()->assertInertia(fn ($page) => $page->has('webActions.parents', 1));
    $this->post(route('web.events.assign-parent', ['event' => $event->id]), [
        'parent_id' => $this->parent->id,
        'title' => 'Tisch decken',
    ])->assertRedirect()->assertSessionHas('success');
});
