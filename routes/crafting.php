<?php

use App\Http\Controllers\CraftingWebController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'family', 'day-cron'])
    ->prefix('crafting')
    ->name('crafting.')
    ->group(function () {
        Route::get('/', [CraftingWebController::class, 'index'])->name('index');
        Route::post('/craft', [CraftingWebController::class, 'craft'])->middleware('feature:crafting')->name('craft');
        Route::post('/jobs/{job}/collect', [CraftingWebController::class, 'collect'])->middleware('feature:crafting')->name('jobs.collect');
        Route::post('/recipes/learn', [CraftingWebController::class, 'learn'])->middleware('feature:crafting')->name('learn');

        Route::post('/storage/deposit', [CraftingWebController::class, 'deposit'])->middleware('feature:building')->name('storage.deposit');
        Route::post('/storage/withdraw', [CraftingWebController::class, 'withdraw'])->middleware('feature:building')->name('storage.withdraw');
        Route::post('/gifts', [CraftingWebController::class, 'gift'])->name('gifts.store');

        Route::post('/trades', [CraftingWebController::class, 'storeTrade'])
            ->middleware(['throttle:20,1', 'feature:alliances'])->name('trades.store');
        Route::post('/trades/{trade}/{action}', [CraftingWebController::class, 'tradeAction'])
            ->whereIn('action', ['accept', 'approve', 'reject', 'cancel'])->name('trades.action');
    });
