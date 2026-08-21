<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use SaniTube\MusicGeneration\Worker\Http\GenerateOnWorkerController;
use SaniTube\MusicGeneration\Worker\Http\VerifyGenerationWorkerToken;

/*
|--------------------------------------------------------------------------
| Generation worker
|--------------------------------------------------------------------------
|
| The endpoint an installation exposes when it *is* a generation worker — the
| host with a GPU and a local ACE-Step. On every other installation, and that is
| almost all of them, the token is unset and this route answers 404: see
| VerifiesSharedToken. Core booting without a worker is the normal case, not a
| degraded one.
|
| Deliberately not in the `web` group and behind no session. A worker is called
| by a queued job on another machine, not by a browser, so a session cookie
| would be a credential nobody has and CSRF a protection against a threat that
| does not apply.
|
| One route, one verb. A worker with a surface is a worker with a surface to
| attack, and this one spends GPU time and writes into shared storage.
|
*/

Route::middleware(VerifyGenerationWorkerToken::class)
    ->post('generation/worker/render', GenerateOnWorkerController::class)
    ->name('generation.worker.render');
