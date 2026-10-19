<?php

use App\Http\Controllers\Api\V1\AppReleaseController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\FamilyController;
use App\Http\Controllers\Api\V1\GameController;
use App\Http\Controllers\Api\V1\HomeAssistantController;
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
            Route::get('shop', [FamilyController::class, 'shop'])->name('shop');
            Route::post('shop/items/{item}/redeem', [FamilyController::class, 'redeem'])->name('shop.redeem');
        });
    });

    Route::middleware(['auth:sanctum', 'abilities:ha', 'family', 'throttle:120,1'])->prefix('ha')->name('ha.')->group(function () {
        Route::get('state', [HomeAssistantController::class, 'state'])->name('state');
        Route::post('events', [HomeAssistantController::class, 'event'])->name('events');
    });
});
