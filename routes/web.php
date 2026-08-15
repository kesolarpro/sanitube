<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Root
|--------------------------------------------------------------------------
|
| SaniTube has no public face. Every screen is behind a session, so the root
| sends a guest to sign in and a signed-in person to the interface.
|
| UI-002 replaces the destination with the dashboard. Until then the only
| screen that exists is the design-system showcase, and pointing at a page
| that has not been built would be worse than pointing at the one that has.
|
*/

Route::middleware('web')->get('/', function () {
    return redirect()->route(auth()->check() ? 'design-system' : 'login');
})->name('home');
