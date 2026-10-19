<?php

use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\FamilyController;
use App\Http\Controllers\FamilyLoginController;
use App\Http\Controllers\HeroController;
use App\Http\Controllers\OnboardingController;
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
    Route::put('family/settings', [FamilyController::class, 'updateSettings'])->name('family.settings.update');

    Route::get('approvals', [ApprovalController::class, 'index'])->name('approvals.index');
    Route::post('completions/{completion}/{decision}', [ApprovalController::class, 'reviewCompletion'])
        ->whereIn('decision', ['approve', 'reject'])->name('completions.review');
    Route::post('redemptions/{redemption}/{decision}', [ApprovalController::class, 'reviewRedemption'])
        ->whereIn('decision', ['approve', 'reject'])->name('redemptions.review');

    Route::get('shop', [RewardShopController::class, 'index'])->name('shop.index');
    Route::post('shop/items', [RewardShopController::class, 'store'])->name('shop.items.store');
    Route::put('shop/items/{item}', [RewardShopController::class, 'update'])->name('shop.items.update');
    Route::delete('shop/items/{item}', [RewardShopController::class, 'destroy'])->name('shop.items.destroy');
    Route::post('shop/items/{item}/redeem', [RewardShopController::class, 'redeem'])->name('shop.items.redeem');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
