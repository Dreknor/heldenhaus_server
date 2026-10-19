<?php

use App\Http\Controllers\Admin\ItemController;
use App\Http\Controllers\Admin\UpdateController;
use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\CharacterController;
use App\Http\Controllers\FamilyController;
use App\Http\Controllers\FamilyLoginController;
use App\Http\Controllers\HeroController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\QuestController;
use App\Http\Controllers\RewardShopController;
use App\Http\Controllers\TaskController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => auth()->check() ? to_route('dashboard') : Inertia::render('Welcome'))->name('home');

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
    Route::post('cron', [HeroController::class, 'runCron'])->name('cron.run');

    Route::post('tasks', [TaskController::class, 'store'])->name('tasks.store');
    Route::put('tasks/{task}', [TaskController::class, 'update'])->name('tasks.update');
    Route::delete('tasks/{task}', [TaskController::class, 'destroy'])->name('tasks.destroy');
    Route::post('tasks/{task}/claim', [TaskController::class, 'claim'])->name('tasks.claim');
    Route::post('tasks/{task}/buy', [TaskController::class, 'buy'])->name('tasks.buy');
    Route::post('assignments/{assignment}/score/{direction}', [TaskController::class, 'score'])
        ->whereIn('direction', ['up', 'down'])->name('assignments.score');

    Route::get('family', [FamilyController::class, 'index'])->name('family.index');
    Route::get('family/tasks', [FamilyController::class, 'tasks'])->name('family.tasks');
    Route::post('family/children', [FamilyController::class, 'storeChild'])->name('family.children.store');
    Route::put('family/members/{member}', [FamilyController::class, 'updateMember'])->name('family.members.update');
    Route::post('family/invites', [FamilyController::class, 'storeInvite'])->name('family.invites.store');
    Route::delete('family/invites/{invite}', [FamilyController::class, 'destroyInvite'])->name('family.invites.destroy');
    Route::post('family/ha-token', [FamilyController::class, 'createHaToken'])->name('family.ha-token');
    Route::put('family/settings', [FamilyController::class, 'updateSettings'])->name('family.settings.update');

    Route::get('approvals', [ApprovalController::class, 'index'])->name('approvals.index');
    Route::post('completions/{completion}/{decision}', [ApprovalController::class, 'reviewCompletion'])
        ->whereIn('decision', ['approve', 'reject'])->name('completions.review');
    Route::post('redemptions/{redemption}/{decision}', [ApprovalController::class, 'reviewRedemption'])
        ->whereIn('decision', ['approve', 'reject'])->name('redemptions.review');

    Route::get('hero', [CharacterController::class, 'hero'])->name('hero');
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
    Route::get('quests', [QuestController::class, 'index'])->name('quests.index');
    Route::post('quests', [QuestController::class, 'start'])->name('quests.start');
    Route::post('quests/{quest}/abandon', [QuestController::class, 'abandon'])->name('quests.abandon');
    Route::get('shop', [RewardShopController::class, 'index'])->name('shop.index');
    Route::post('shop/items', [RewardShopController::class, 'store'])->name('shop.items.store');
    Route::put('shop/items/{item}', [RewardShopController::class, 'update'])->name('shop.items.update');
    Route::delete('shop/items/{item}', [RewardShopController::class, 'destroy'])->name('shop.items.destroy');
    Route::post('shop/items/{item}/redeem', [RewardShopController::class, 'redeem'])->name('shop.items.redeem');
});

Route::middleware(['auth', 'can:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('updates', [UpdateController::class, 'index'])->name('updates.index');
    Route::post('updates/check', [UpdateController::class, 'check'])->name('updates.check');
    Route::post('updates/start', [UpdateController::class, 'start'])->name('updates.start');
    Route::post('updates/{update}/step', [UpdateController::class, 'step'])->name('updates.step');
    Route::post('updates/rollback', [UpdateController::class, 'rollback'])->name('updates.rollback');

    Route::get('items', [ItemController::class, 'index'])->name('items.index');
    Route::post('items', [ItemController::class, 'store'])->name('items.store');
    Route::put('items/{item}', [ItemController::class, 'update'])->name('items.update');
    Route::delete('items/{item}', [ItemController::class, 'destroy'])->name('items.destroy');
    Route::put('drop-settings', [ItemController::class, 'updateSettings'])->name('drop-settings.update');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
