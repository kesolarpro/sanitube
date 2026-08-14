<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use SaniTube\Api\Http\Controllers\V1\HealthController;
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

    Route::get('health', [HealthController::class, 'live'])->name('health');

    Route::middleware(VerifyHealthToken::class)->group(function (): void {
        Route::get('health/ready', [HealthController::class, 'ready'])->name('health.ready');
        Route::get('system/capabilities', [HealthController::class, 'capabilities'])->name('system.capabilities');
    });

});
