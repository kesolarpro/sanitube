<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use SaniTube\Ui\Http\Controllers\DesignSystemController;
use SaniTube\Ui\Http\Middleware\HandleInertiaRequests;

/*
|--------------------------------------------------------------------------
| Interface
|--------------------------------------------------------------------------
|
| Every application screen sits behind SEC-001: `auth` for a session, `active`
| so a deactivated account loses access at the next request rather than when
| its remember-me cookie expires.
|
| There is one route here on purpose. UI-001 delivers the design system and the
| shell; the business screens wait until the system has been reviewed, which is
| the whole point of doing this ticket first.
|
*/

Route::middleware(['web', HandleInertiaRequests::class, 'auth', 'active'])->group(function (): void {
    Route::get('design-system', DesignSystemController::class)->name('design-system');
});
