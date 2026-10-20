<?php

use App\Domain\Dungeons\DungeonService;
use App\Http\Controllers\AchievementController;
use App\Http\Controllers\Api\V1\AppReleaseController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CharacterController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\FamilyController;
use App\Http\Controllers\Api\V1\GameController;
use App\Http\Controllers\Api\V1\HomeAssistantController;
use App\Http\Controllers\CraftingController;
use App\Http\Controllers\DungeonController;
use App\Http\Controllers\FamilyHubController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\QuestController;
use App\Http\Controllers\SocialController;
use App\Http\Controllers\SupportController;
use Illuminate\Support\Facades\Route;

/*
 * REST-API v1 für die Flutter-App und Home Assistant (docs/konzept/05-architektur.md §5.4)
 * App-Tokens haben die Fähigkeit „app“, Home-Assistant-Tokens „ha“.
 */
Route::prefix('v1')->name('api.v1.')->middleware('app-version')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1')->name('auth.login');
    Route::get('auth/family/{code}', [AuthController::class, 'family'])->middleware('throttle:30,1')->name('auth.family');
    Route::post('auth/pin', [AuthController::class, 'pin'])->middleware('throttle:20,1')->name('auth.pin');

    Route::get('app/version', [AppReleaseController::class, 'version'])->name('app.version');
    Route::get('app/download/{release}', [AppReleaseController::class, 'download'])->middleware('signed')->name('app.download');

    Route::middleware(['auth:sanctum', 'abilities:app'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('me', [AuthController::class, 'me'])->name('me');
        Route::post('admin/app-releases', [AppReleaseController::class, 'store'])->name('admin.app-releases.store');
        Route::post('devices', [DeviceController::class, 'store'])->name('devices.store');
        Route::delete('devices', [DeviceController::class, 'destroy'])->name('devices.destroy');
        Route::get('notifications/settings', [DeviceController::class, 'settings'])->name('notifications.settings');
        Route::put('notifications/settings', [DeviceController::class, 'updateSettings'])->name('notifications.settings.update');

        Route::middleware(['family', 'day-cron'])->group(function () {
            Route::get('board', [GameController::class, 'board'])->name('board');
            Route::post('cron', [GameController::class, 'cron'])->name('cron');
            Route::post('sync/actions', [GameController::class, 'sync'])->name('sync');
            Route::post('assignments/{assignment}/score/{direction}', [GameController::class, 'score'])
                ->whereIn('direction', ['up', 'down'])->name('assignments.score');

            Route::post('tasks', [GameController::class, 'storeTask'])->name('tasks.store');
            Route::put('tasks/{task}', [GameController::class, 'updateTask'])->name('tasks.update');
            Route::delete('tasks/{task}', [GameController::class, 'destroyTask'])->name('tasks.destroy');
            Route::post('tasks/{task}/claim', [GameController::class, 'claim'])->name('tasks.claim');
            Route::post('tasks/{task}/buy', [GameController::class, 'buy'])->name('tasks.buy');

            Route::get('family', [FamilyController::class, 'show'])->name('family');
            Route::get('approvals', [FamilyController::class, 'approvals'])->name('approvals');
            Route::post('completions/{completion}/{decision}', [FamilyController::class, 'reviewCompletion'])
                ->whereIn('decision', ['approve', 'reject'])->name('completions.review');
            Route::post('redemptions/{redemption}/{decision}', [FamilyController::class, 'reviewRedemption'])
                ->whereIn('decision', ['approve', 'reject'])->name('redemptions.review');
            Route::get('hero', [CharacterController::class, 'hero'])->name('hero');
            Route::put('hero/avatar', [CharacterController::class, 'saveAvatar'])->name('hero.avatar');
            Route::post('hero/allocate', [CharacterController::class, 'allocate'])->name('hero.allocate');
            Route::post('hero/class', [CharacterController::class, 'chooseClass'])->name('hero.class');
            Route::post('hero/cast', [CharacterController::class, 'cast'])->name('hero.cast');
            Route::post('hero/second-chance', [CharacterController::class, 'secondChance'])->name('hero.second-chance');
            Route::post('hero/unequip', [CharacterController::class, 'unequip'])->name('hero.unequip');
            Route::get('inventory', [CharacterController::class, 'inventory'])->name('inventory');
            Route::post('inventory/{item}/buy', [CharacterController::class, 'buy'])->name('inventory.buy');
            Route::post('inventory/{item}/equip', [CharacterController::class, 'equip'])->name('inventory.equip');
            Route::post('inventory/{item}/use', [CharacterController::class, 'use'])->name('inventory.use');
            Route::post('pets/hatch', [CharacterController::class, 'hatch'])->name('pets.hatch');
            Route::post('pets/{pet}/feed', [CharacterController::class, 'feed'])->name('pets.feed');
            Route::get('feed', [FamilyHubController::class, 'feed'])->name('feed');
            Route::post('feed/{entry}/react', [FamilyHubController::class, 'react'])->name('feed.react');
            Route::get('allowance', [FamilyHubController::class, 'allowance'])->name('allowance');
            Route::post('allowance', [FamilyHubController::class, 'storeAllowance'])->name('allowance.store');
            Route::get('family/report', [FamilyHubController::class, 'report'])->name('family.report');
            Route::get('achievements', [AchievementController::class, 'index'])->name('achievements');
            Route::post('achievements/seen', [AchievementController::class, 'seen'])->name('achievements.seen');
            Route::get('quests', [QuestController::class, 'index'])->name('quests');
            Route::post('quests', [QuestController::class, 'start'])->name('quests.start');
            Route::post('quests/{quest}/abandon', [QuestController::class, 'abandon'])->name('quests.abandon');

            Route::get('shop', [FamilyController::class, 'shop'])->name('shop');
            Route::post('shop/items/{item}/redeem', [FamilyController::class, 'redeem'])->name('shop.redeem');

            Route::get('social', [SocialController::class, 'index'])->name('social');
            Route::post('alliances', [SocialController::class, 'invite'])->name('alliances.invite');
            Route::post('alliances/join', [SocialController::class, 'join'])->middleware('throttle:10,1')->name('alliances.join');
            Route::delete('alliances/{alliance}', [SocialController::class, 'endAlliance'])->name('alliances.destroy');
            Route::post('challenges', [SocialController::class, 'storeChallenge'])->name('challenges.store');
            Route::post('challenges/{challenge}/join', [SocialController::class, 'joinChallenge'])->name('challenges.join');
            Route::post('challenges/{challenge}/leave', [SocialController::class, 'leaveChallenge'])->name('challenges.leave');
            Route::delete('challenges/{challenge}', [SocialController::class, 'destroyChallenge'])->name('challenges.destroy');
            Route::get('messages', [SocialController::class, 'messages'])->name('messages');
            Route::post('messages', [SocialController::class, 'sendMessage'])->middleware('throttle:30,1')->name('messages.store');

            // Crafting & Housing (Konzept §2.5, §2.6)
            Route::get('recipes', [CraftingController::class, 'recipes'])->name('recipes');
            Route::post('craft', [CraftingController::class, 'craft'])->name('craft');
            Route::post('craft/{job}/collect', [CraftingController::class, 'collect'])->name('craft.collect');
            Route::post('recipes/learn', [CraftingController::class, 'learn'])->name('recipes.learn');
            Route::get('storage', [CraftingController::class, 'storage'])->name('storage');
            Route::post('storage/deposit', [CraftingController::class, 'deposit'])->name('storage.deposit');
            Route::post('storage/withdraw', [CraftingController::class, 'withdraw'])->name('storage.withdraw');
            Route::post('gifts', [CraftingController::class, 'gift'])->name('gifts');
            Route::get('trade-offers', [CraftingController::class, 'trades'])->name('trades');
            Route::post('trade-offers', [CraftingController::class, 'storeTrade'])->middleware('throttle:20,1')->name('trades.store');
            Route::post('trade-offers/{trade}/{action}', [CraftingController::class, 'tradeAction'])
                ->whereIn('action', ['accept', 'approve', 'reject', 'cancel'])->name('trades.action');
            Route::get('home', [HomeController::class, 'show'])->name('home');
            Route::get('families/{family}/home', [HomeController::class, 'visit'])->name('home.visit');
            Route::get('rooms/{room}', [HomeController::class, 'room'])->name('rooms.show');
            Route::put('rooms/{room}/layout', [HomeController::class, 'layout'])->name('rooms.layout');
            Route::post('build-projects', [HomeController::class, 'startProject'])->name('build.start');
            Route::post('build-projects/{project}/contribute', [HomeController::class, 'contribute'])->name('build.contribute');
            Route::delete('build-projects/{project}', [HomeController::class, 'cancelProject'])->name('build.cancel');
            Route::post('garden/{slot}/{action}', [HomeController::class, 'gardenAction'])->whereNumber('slot')
                ->whereIn('action', ['sow', 'water', 'harvest'])->name('garden.action');
            Route::post('families/{family}/guestbook', [HomeController::class, 'writeGuestbook'])->middleware('throttle:20,1')->name('guestbook.store');
            Route::delete('guestbook/{entry}', [HomeController::class, 'deleteGuestbook'])->name('guestbook.destroy');

            // Dungeon-Quests (Konzept §2.7)
            Route::get('dungeons/current', [DungeonController::class, 'current'])->name('dungeons.current');
            Route::post('dungeons/solo', [DungeonController::class, 'solo'])->name('dungeons.solo');
            Route::get('dungeons/{dungeon}', [DungeonController::class, 'show'])->name('dungeons.show');
            Route::get('dungeons/{dungeon}/log', [DungeonController::class, 'log'])->name('dungeons.log');
            Route::post('dungeons/{dungeon}/{action}', [DungeonController::class, 'act'])->whereIn('action', DungeonService::ACTIONS)
                ->middleware('throttle:60,1')->name('dungeons.act');

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
        });
    });

    Route::middleware(['auth:sanctum', 'abilities:ha', 'family', 'throttle:120,1', 'ha-audit'])->prefix('ha')->name('ha.')->group(function () {
        Route::get('state', [HomeAssistantController::class, 'state'])->name('state');
        Route::post('events', [HomeAssistantController::class, 'event'])->name('events');
        Route::get('quest.svg', [HomeAssistantController::class, 'questImage'])->name('quest.image');
        Route::post('tasks', [HomeAssistantController::class, 'createTask'])->name('tasks.store');
        Route::post('tasks/{task}/complete', [HomeAssistantController::class, 'completeTask'])->name('tasks.complete');
        Route::post('garden/{slot}/water', [HomeAssistantController::class, 'waterGarden'])->whereNumber('slot')->name('garden.water');
        Route::post('dungeon/mechanism', [HomeAssistantController::class, 'dungeonMechanism'])->name('dungeon.mechanism');

        // Eltern-Funktionen nur mit Token-Fähigkeit „ha:approve“ (beim Erzeugen des Tokens wählbar)
        Route::middleware('abilities:ha:approve')->group(function () {
            Route::get('approvals', [FamilyController::class, 'approvals'])->name('approvals');
            Route::post('completions/{completion}/{decision}', [FamilyController::class, 'reviewCompletion'])
                ->whereIn('decision', ['approve', 'reject'])->name('completions.review');
            Route::post('redemptions/{redemption}/{decision}', [FamilyController::class, 'reviewRedemption'])
                ->whereIn('decision', ['approve', 'reject'])->name('redemptions.review');
            Route::post('stars/{member}', [HomeAssistantController::class, 'grantStars'])->whereNumber('member')->name('stars');
        });

        // Spielen über Home Assistant: dieselben Aktionen wie in der App, stellvertretend für ein
        // Familienmitglied. Eltern-Funktionen (Freigaben, Aufgabenverwaltung, Taschengeld) fehlen bewusst.
        Route::prefix('members/{member}')->name('members.')->whereNumber('member')
            ->middleware(['ha-member', 'day-cron'])->group(function () {
                Route::get('avatar.svg', [HomeAssistantController::class, 'avatar'])->name('avatar');

                Route::get('board', [GameController::class, 'board'])->name('board');
                Route::post('cron', [GameController::class, 'cron'])->name('cron');
                Route::post('assignments/{assignment}/score/{direction}', [GameController::class, 'score'])
                    ->whereIn('direction', ['up', 'down'])->name('assignments.score');
                Route::post('tasks/{task}/claim', [GameController::class, 'claim'])->name('tasks.claim');
                Route::post('tasks/{task}/buy', [GameController::class, 'buy'])->name('tasks.buy');

                Route::get('hero', [CharacterController::class, 'hero'])->name('hero');
                Route::post('hero/allocate', [CharacterController::class, 'allocate'])->name('hero.allocate');
                Route::post('hero/class', [CharacterController::class, 'chooseClass'])->name('hero.class');
                Route::post('hero/cast', [CharacterController::class, 'cast'])->name('hero.cast');
                Route::post('hero/unequip', [CharacterController::class, 'unequip'])->name('hero.unequip');
                Route::get('inventory', [CharacterController::class, 'inventory'])->name('inventory');
                Route::post('inventory/{item}/buy', [CharacterController::class, 'buy'])->name('inventory.buy');
                Route::post('inventory/{item}/equip', [CharacterController::class, 'equip'])->name('inventory.equip');
                Route::post('inventory/{item}/use', [CharacterController::class, 'use'])->name('inventory.use');
                Route::post('pets/hatch', [CharacterController::class, 'hatch'])->name('pets.hatch');
                Route::post('pets/{pet}/feed', [CharacterController::class, 'feed'])->name('pets.feed');

                Route::get('quests', [QuestController::class, 'index'])->name('quests');
                Route::post('quests', [QuestController::class, 'start'])->name('quests.start');

                Route::get('shop', [FamilyController::class, 'shop'])->name('shop');
                Route::post('shop/items/{item}/redeem', [FamilyController::class, 'redeem'])->name('shop.redeem');
            });
    });
});
