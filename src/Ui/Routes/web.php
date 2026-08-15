<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use SaniTube\Ui\Http\Controllers\Catalog\TrackDetailController;
use SaniTube\Ui\Http\Controllers\Catalog\TrackIndexController;
use SaniTube\Ui\Http\Controllers\DashboardController;
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
| The design system screen stays: it is the reference the other screens are
| built against, and a regression in a primitive shows up there first.
|
*/

Route::middleware(['web', HandleInertiaRequests::class, 'auth', 'active'])->group(function (): void {
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::get('catalog/tracks', TrackIndexController::class)->name('catalog.tracks');
    Route::get('catalog/tracks/{track}', TrackDetailController::class)->name('catalog.tracks.show');

    Route::get('design-system', DesignSystemController::class)->name('design-system');
});
