<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use SaniTube\Identity\Http\Controllers\LoginController;

/*
|--------------------------------------------------------------------------
| Identity
|--------------------------------------------------------------------------
|
| Session authentication for the first-party interface. The internal API
| token stays a server-to-server credential and is never handed to a browser.
|
| The login POST is throttled at the route as well as in the service: the
| service's limiter is per email and IP, this one bounds the raw request rate
| so a flood cannot cost a password hash each time.
|
*/

Route::middleware('web')->group(function (): void {
    Route::get('login', [LoginController::class, 'show'])->name('login');

    Route::post('login', [LoginController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('login.store');

    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');
});

/*
 * Probe routes.
 *
 * Registered only in the testing environment, so the middleware that gates the
 * whole interface can be asserted against real routes rather than by
 * instantiating it directly — the second proves the class, the first proves the
 * wiring, and it is the wiring that goes wrong.
 *
 * They never exist in production: `app()->environment('testing')` is checked
 * at registration, not at request time.
 */
if (app()->environment('testing')) {
    Route::middleware(['web', 'auth', 'active'])->group(function (): void {
        Route::get('probe-active', fn () => response('ok'));

        Route::middleware('can.role:distribute')->get('probe-distribute', fn () => response('ok'));
        Route::middleware('can.role:not-a-real-ability')->get('probe-unknown-ability', fn () => response('ok'));
    });
}
