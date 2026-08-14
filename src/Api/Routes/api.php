<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use SaniTube\Api\Http\Controllers\V1\HealthController;
use SaniTube\Api\Http\Middleware\ThrottleHealthRequests;
use SaniTube\Api\Http\Middleware\VerifyHealthToken;

/*
|--------------------------------------------------------------------------
| REST API v1
|--------------------------------------------------------------------------
|
| Versioned from the first commit. `/api/v2` will be added alongside this
| file rather than by editing it, so v1 consumers are never broken by a new
| version. The `api` prefix itself comes from config/sanitube.php and is
| applied by the module loader.
|
*/

Route::prefix('v1')->name('api.v1.')->group(function (): void {

    /*
     * Liveness — public, and deliberately the cheapest endpoint in the
     * application. It answers from the process alone: no database, no cache
     * store, no storage provider, no capability detector. That is the whole
     * point of a liveness probe, and it is asserted by a test that counts
     * database queries.
     *
     * It is excluded from the global `throttle:api` middleware because that
     * counts through the default cache store — the database in a portable
     * install — and gets its own file-backed throttle instead.
     */
    Route::get('health/live', [HealthController::class, 'live'])
        ->name('health.live')
        ->withoutMiddleware('throttle:api')
        ->middleware(ThrottleHealthRequests::class.':live');

    /*
     * Readiness and the capability report describe the environment in detail
     * and do run every detector, so they are token-protected and throttled
     * more tightly than liveness.
     */
    Route::middleware([
        ThrottleHealthRequests::class.':privileged',
        VerifyHealthToken::class,
    ])->group(function (): void {
        Route::get('health/ready', [HealthController::class, 'ready'])->name('health.ready');
        Route::get('system/capabilities', [HealthController::class, 'capabilities'])->name('system.capabilities');
    });

});
