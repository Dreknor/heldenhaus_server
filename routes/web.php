<?php

use App\Domain\Dungeons\DungeonService;
use App\Http\Controllers\AchievementController;
use App\Http\Controllers\Admin\AiRequestController;
use App\Http\Controllers\Admin\ItemController;
use App\Http\Controllers\Admin\SceneBackgroundController;
use App\Http\Controllers\Admin\UpdateController;
use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\CharacterController;
use App\Http\Controllers\CreditsController;
use App\Http\Controllers\DungeonWebController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\FamilyController;
use App\Http\Controllers\FamilyHubController;
use App\Http\Controllers\FamilyLoginController;
use App\Http\Controllers\HeroController;
use App\Http\Controllers\HousingController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\QuestController;
use App\Http\Controllers\RewardShopController;
use App\Http\Controllers\SocialController;
use App\Http\Controllers\SupportController;
use App\Http\Controllers\TaskController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => auth()->check() ? to_route('dashboard') : Inertia::render('Welcome'))->name('home');
Route::get('credits', CreditsController::class)->name('credits');

Route::middleware('guest')->group(function () {
    Route::get('family-login', [FamilyLoginController::class, 'show'])->name('family-login');
    Route::post('family-login', [FamilyLoginController::class, 'login'])->middleware('throttle:20,1');
});

Route::middleware('auth')->group(function () {
    Route::get('onboarding', [OnboardingController::class, 'show'])->name('onboarding');
    Route::post('onboarding/create', [OnboardingController::class, 'create'])->name('onboarding.create');
    Route::post('onboarding/join', [OnboardingController::class, 'join'])->name('onboarding.join');
});

Route::middleware(['auth', 'family', 'day-cron'])->group(function () {
    Route::get('dashboard', [HeroController::class, 'index'])->name('dashboard');
    Route::get('dungeons', [DungeonWebController::class, 'index'])->name('dungeons.index');
    Route::post('dungeons/solo', [DungeonWebController::class, 'solo'])->name('dungeons.solo');
    Route::post('dungeons/{dungeon}/{action}', [DungeonWebController::class, 'act'])
        ->whereIn('action', DungeonService::ACTIONS)->middleware('throttle:60,1')->name('dungeons.act');

    Route::get('events', [EventController::class, 'webIndex'])->name('web.events.index');
    Route::get('events/history', [EventController::class, 'webHistory'])->name('web.events.history');
    Route::get('events/{event}', [EventController::class, 'webShow'])->whereNumber('event')->name('web.events.show');
    Route::post('events/{event}/advent/{day}', [EventController::class, 'webAdvent'])
        ->whereNumber('event')->whereNumber('day')->middleware('throttle:20,1')->name('web.events.advent');
    Route::post('events/{event}/merchant/buy', [EventController::class, 'webMerchantBuy'])
        ->whereNumber('event')->middleware('throttle:20,1')->name('web.events.merchant');
    Route::post('events/{event}/secret-help', [EventController::class, 'webSecretHelp'])
        ->whereNumber('event')->middleware('throttle:20,1')->name('web.events.secret-help');
    Route::post('events/{event}/joker', [EventController::class, 'webJoker'])
        ->whereNumber('event')->name('web.events.joker');
    Route::post('events/{event}/assign-parent', [EventController::class, 'webAssignParent'])
        ->whereNumber('event')->name('web.events.assign-parent');
    Route::post('events/{event}/class-trial', [EventController::class, 'webClassTrial'])
        ->whereNumber('event')->name('web.events.class-trial');
    Route::get('family/event-settings', [EventController::class, 'webSettings'])->name('web.events.settings');
    Route::put('family/event-settings', [EventController::class, 'webUpdateSettings'])
        ->name('web.events.settings.update');
    Route::get('housing', [HousingController::class, 'index'])->name('housing.index');
    Route::get('housing/visit/{family}', [HousingController::class, 'visit'])->name('housing.visit');
    Route::put('housing/rooms/{room}', [HousingController::class, 'customize'])->name('housing.rooms.customize');
    Route::put('housing/rooms/{room}/layout', [HousingController::class, 'layout'])->name('housing.rooms.layout');
    Route::post('housing/projects', [HousingController::class, 'startProject'])->name('housing.projects.start');
    Route::post('housing/projects/{project}/contribute', [HousingController::class, 'contribute'])->name('housing.projects.contribute');
    Route::delete('housing/projects/{project}', [HousingController::class, 'cancelProject'])->name('housing.projects.cancel');
    Route::post('housing/garden/{slot}/{action}', [HousingController::class, 'gardenAction'])
        ->whereNumber('slot')->whereIn('action', ['sow', 'water', 'harvest'])->name('housing.garden.action');
    Route::post('housing/guestbook', [HousingController::class, 'writeGuestbook'])->name('housing.guestbook.store');
    Route::post('housing/visit/{family}/guestbook', [HousingController::class, 'writeGuestbookTo'])->name('housing.visit.guestbook.store');
    Route::delete('housing/guestbook/{entry}', [HousingController::class, 'deleteGuestbook'])->name('housing.guestbook.destroy');
    Route::post('cron', [HeroController::class, 'runCron'])->name('cron.run');

    Route::post('tasks', [TaskController::class, 'store'])->name('tasks.store');
    Route::put('tasks/{task}', [TaskController::class, 'update'])->name('tasks.update');
    Route::delete('tasks/{task}', [TaskController::class, 'destroy'])->name('tasks.destroy');
    Route::post('tasks/{task}/claim', [TaskController::class, 'claim'])->name('tasks.claim');
    Route::post('tasks/{task}/buy', [TaskController::class, 'buy'])->name('tasks.buy');
    Route::post('assignments/{assignment}/score/{direction}', [TaskController::class, 'score'])
        ->whereIn('direction', ['up', 'down'])->name('assignments.score');

    Route::get('family', [FamilyController::class, 'index'])->name('family.index');
    Route::get('family/settings', [FamilyController::class, 'settings'])->name('family.settings');
    Route::get('family/tasks', [FamilyController::class, 'tasks'])->name('family.tasks');
    Route::post('family/children', [FamilyController::class, 'storeChild'])->name('family.children.store');
    Route::put('family/members/{member}', [FamilyController::class, 'updateMember'])->name('family.members.update');
    Route::post('family/invites', [FamilyController::class, 'storeInvite'])->name('family.invites.store');
    Route::delete('family/invites/{invite}', [FamilyController::class, 'destroyInvite'])->name('family.invites.destroy');
    Route::post('family/ha-token', [FamilyController::class, 'createHaToken'])->name('family.ha-token');
    Route::put('family/ha-webhook', [FamilyController::class, 'saveHaWebhook'])->name('family.ha-webhook.save');
    Route::post('family/ha-webhook/test', [FamilyController::class, 'testHaWebhook'])->name('family.ha-webhook.test');
    Route::delete('family/ha-webhook', [FamilyController::class, 'destroyHaWebhook'])->name('family.ha-webhook.destroy');
    Route::post('family/members/{member}/stars', [FamilyController::class, 'grantStars'])->name('family.members.stars');
    Route::put('family/settings', [FamilyController::class, 'updateSettings'])->name('family.settings.update');
    Route::put('family/settings/weather', [FamilyController::class, 'updateWeatherSettings'])->name('family.weather.update');

    Route::get('approvals', [ApprovalController::class, 'index'])->name('approvals.index');
    Route::post('completions/{completion}/{decision}', [ApprovalController::class, 'reviewCompletion'])
        ->whereIn('decision', ['approve', 'reject'])->name('completions.review');
    Route::post('redemptions/{redemption}/{decision}', [ApprovalController::class, 'reviewRedemption'])
        ->whereIn('decision', ['approve', 'reject'])->name('redemptions.review');

    Route::get('hero', [CharacterController::class, 'hero'])->name('hero');
    Route::put('hero/avatar', [CharacterController::class, 'saveAvatar'])->name('hero.avatar');
    Route::post('hero/allocate', [CharacterController::class, 'allocate'])->name('hero.allocate');
    Route::post('hero/class', [CharacterController::class, 'chooseClass'])->name('hero.class');
    Route::post('hero/cast', [CharacterController::class, 'cast'])->name('hero.cast');
    Route::post('hero/second-chance', [CharacterController::class, 'secondChance'])->name('hero.second-chance');
    Route::post('hero/unequip', [CharacterController::class, 'unequip'])->name('hero.unequip');

    Route::get('inventory', [CharacterController::class, 'inventory'])->name('inventory.index');
    Route::post('inventory/{item}/buy', [CharacterController::class, 'buy'])->name('inventory.buy');
    Route::post('inventory/{item}/equip', [CharacterController::class, 'equip'])->name('inventory.equip');
    Route::post('inventory/{item}/use', [CharacterController::class, 'use'])->name('inventory.use');
    Route::post('pets/hatch', [CharacterController::class, 'hatch'])->name('pets.hatch');
    Route::post('pets/{pet}/feed', [CharacterController::class, 'feed'])->name('pets.feed');
    Route::post('feed/{entry}/react', [FamilyHubController::class, 'react'])->name('feed.react');
    Route::get('allowance', [FamilyHubController::class, 'allowance'])->name('allowance.index');
    Route::post('allowance', [FamilyHubController::class, 'storeAllowance'])->name('allowance.store');
    Route::get('family/report', [FamilyHubController::class, 'report'])->name('family.report');

    Route::get('achievements', [AchievementController::class, 'index'])->name('achievements.index');
    Route::post('achievements/seen', [AchievementController::class, 'seen'])->name('achievements.seen');

    Route::get('quests', [QuestController::class, 'index'])->name('quests.index');
    Route::post('quests', [QuestController::class, 'start'])->name('quests.start');
    Route::post('quests/{quest}/abandon', [QuestController::class, 'abandon'])->name('quests.abandon');
    Route::get('social', [SocialController::class, 'index'])->name('social.index');
    Route::post('alliances', [SocialController::class, 'invite'])->name('alliances.invite');
    Route::post('alliances/join', [SocialController::class, 'join'])->middleware('throttle:10,1')->name('alliances.join');
    Route::delete('alliances/{alliance}', [SocialController::class, 'endAlliance'])->name('alliances.destroy');
    Route::post('challenges', [SocialController::class, 'storeChallenge'])->name('challenges.store');
    Route::post('challenges/{challenge}/join', [SocialController::class, 'joinChallenge'])->name('challenges.join');
    Route::post('challenges/{challenge}/leave', [SocialController::class, 'leaveChallenge'])->name('challenges.leave');
    Route::delete('challenges/{challenge}', [SocialController::class, 'destroyChallenge'])->name('challenges.destroy');
    Route::post('messages', [SocialController::class, 'sendMessage'])->middleware('throttle:30,1')->name('messages.store');

    // Fokus & Alltagshilfen (Konzept §11)
    Route::get('me/support-settings', [SupportController::class, 'settings'])->name('support.settings');
    Route::put('me/support-settings', [SupportController::class, 'updateSettings'])->name('support.settings.update');
    Route::get('members/{member}/support-settings', [SupportController::class, 'settings'])->name('members.support.settings');
    Route::put('members/{member}/support-settings', [SupportController::class, 'updateSettings'])->name('members.support.settings.update');
    Route::put('family/support', [SupportController::class, 'updateFamilySettings'])->name('family.support');
    Route::post('me/low-energy', [SupportController::class, 'startLowEnergy'])->name('low-energy.start');
    Route::delete('me/low-energy', [SupportController::class, 'cancelLowEnergy'])->name('low-energy.cancel');
    Route::post('members/{member}/low-energy', [SupportController::class, 'startLowEnergy'])->name('members.low-energy.start');
    Route::delete('members/{member}/low-energy', [SupportController::class, 'cancelLowEnergy'])->name('members.low-energy.cancel');
    Route::post('inbox', [SupportController::class, 'inbox'])->name('inbox.store');
    Route::post('inbox/{task}/sort', [SupportController::class, 'sortInbox'])->name('inbox.sort');
    Route::post('assignments/{assignment}/steps/{step}/score', [SupportController::class, 'scoreStep'])->whereNumber('step')->name('steps.score');
    Route::post('tasks/{task}/steps/suggest', [SupportController::class, 'suggestSteps'])->middleware('throttle:20,1')->name('steps.suggest');
    Route::put('tasks/{task}/steps', [SupportController::class, 'saveSteps'])->name('steps.save');
    Route::post('focus-sessions', [SupportController::class, 'startFocus'])->name('focus.start');
    Route::post('focus-sessions/{session}/{action}', [SupportController::class, 'focusAction'])
        ->whereIn('action', ['finish', 'extend', 'abort', 'invite'])->name('focus.action');

    Route::get('shop', [RewardShopController::class, 'index'])->name('shop.index');
    Route::post('shop/items', [RewardShopController::class, 'store'])->name('shop.items.store');
    Route::put('shop/items/{item}', [RewardShopController::class, 'update'])->name('shop.items.update');
    Route::delete('shop/items/{item}', [RewardShopController::class, 'destroy'])->name('shop.items.destroy');
    Route::post('shop/items/{item}/redeem', [RewardShopController::class, 'redeem'])->name('shop.items.redeem');
});

Route::middleware(['auth', 'can:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('ai-requests', [AiRequestController::class, 'index'])->name('ai-requests.index');
    Route::get('updates', [UpdateController::class, 'index'])->name('updates.index');
    Route::get('updates/status', [UpdateController::class, 'status'])->name('updates.status');
    Route::post('updates/check', [UpdateController::class, 'check'])->name('updates.check');
    Route::post('updates/start', [UpdateController::class, 'start'])->name('updates.start');
    Route::post('updates/cancel', [UpdateController::class, 'cancel'])->name('updates.cancel');
    Route::post('updates/up', [UpdateController::class, 'up'])->name('updates.up');
    Route::post('updates/backgrounds/chunk', [SceneBackgroundController::class, 'chunk'])->name('updates.backgrounds.chunk');
    Route::post('updates/backgrounds/import', [SceneBackgroundController::class, 'import'])->name('updates.backgrounds.import');
    Route::delete('updates/backgrounds', [SceneBackgroundController::class, 'destroy'])->name('updates.backgrounds.destroy');

    Route::get('items', [ItemController::class, 'index'])->name('items.index');
    Route::post('items', [ItemController::class, 'store'])->name('items.store');
    Route::put('items/{item}', [ItemController::class, 'update'])->name('items.update');
    Route::delete('items/{item}', [ItemController::class, 'destroy'])->name('items.destroy');
    Route::put('drop-settings', [ItemController::class, 'updateSettings'])->name('drop-settings.update');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
