<?php

use App\Http\Controllers\CouncilController;
use App\Http\Controllers\GrowingUpController;
use App\Http\Controllers\ParentHelpController;
use App\Http\Controllers\ProposalController;
use App\Http\Controllers\VoteController;
use Illuminate\Support\Facades\Route;

/*
 * Familienrat und Mitbestimmung (§14.4), Hilfen für Eltern (§14.9), Altersübergänge (§14.11) – Web.
 * Familienrelevant, deshalb ohne Feature-Middleware (§13.1).
 */
Route::middleware(['auth', 'family', 'day-cron'])->group(function () {
    Route::get('family/council', [CouncilController::class, 'show'])->name('council.show');
    Route::post('family/council', [CouncilController::class, 'act'])->name('council.act');
    Route::put('family/council-settings', [CouncilController::class, 'updateSettings'])->name('council.settings');

    Route::get('proposals', [ProposalController::class, 'index'])->name('proposals.index');
    Route::post('proposals', [ProposalController::class, 'store'])->middleware('throttle:20,1')->name('proposals.store');
    Route::post('proposals/{proposal}/accept', [ProposalController::class, 'accept'])->name('proposals.accept');
    Route::post('proposals/{proposal}/decline', [ProposalController::class, 'decline'])->name('proposals.decline');

    Route::post('votes', [VoteController::class, 'store'])->name('votes.store');
    Route::get('votes/{vote}', [VoteController::class, 'show'])->name('votes.show');
    Route::post('votes/{vote}', [VoteController::class, 'act'])->name('votes.act');

    Route::get('task-templates', [ParentHelpController::class, 'templates'])->name('task-templates.index');
    Route::post('task-templates/{key}/adopt', [ParentHelpController::class, 'adopt'])->name('task-templates.adopt');
    Route::get('family/members/{member}/start', [ParentHelpController::class, 'wizard'])->name('family.start-wizard');
    Route::post('family/members/{member}/starter-tasks', [ParentHelpController::class, 'storeStarter'])->name('family.starter-tasks');
    Route::get('shop/price-help', [ParentHelpController::class, 'priceHelp'])->name('shop.price-help');
    Route::get('help/parents', [ParentHelpController::class, 'guide'])->name('help.parents');

    Route::get('me/growing-up', [GrowingUpController::class, 'show'])->name('growing-up');
    Route::post('me/adult-profile', [GrowingUpController::class, 'adultProfile'])->middleware('throttle:10,1')->name('adult-profile');
    Route::post('me/hints/{key}/dismiss', [GrowingUpController::class, 'dismiss'])->name('hints.dismiss');
});
