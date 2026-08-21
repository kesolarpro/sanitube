<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use SaniTube\Worker\Http\Controllers\RunWorkerJobController;
use SaniTube\Worker\Http\Controllers\WorkerIdentityController;
use SaniTube\Worker\Http\Middleware\VerifyWorkerToken;

/*
|--------------------------------------------------------------------------
| Worker
|--------------------------------------------------------------------------
|
| What an installation exposes when it *is* a worker — a host with a GPU, or
| with FFmpeg, or with anything Core's own host has not got. On every other
| installation, and that is almost all of them, the token is unset and these
| routes answer 404: see VerifiesSharedToken. Core booting without a worker is
| the normal case, not a degraded one.
|
| Two routes, and only two however many capabilities exist. A handshake, and a
| job. GEN-008 shipped a generation-specific endpoint; WRK-001 replaced it with
| this, before a second subsystem could copy the pattern and leave the platform
| with one boundary per kind of work.
|
| Deliberately outside the `web` group and behind no session. A worker is called
| by a queued job on another machine, so a session cookie would be a credential
| nobody has and CSRF a protection against a threat that does not apply.
|
*/

Route::middleware(VerifyWorkerToken::class)->prefix('worker')->group(function (): void {
    Route::get('/', WorkerIdentityController::class)->name('worker.identity');

    Route::post('jobs/{capability}', RunWorkerJobController::class)
        ->where('capability', '[a-z-]+')
        ->name('worker.jobs.run');
});
