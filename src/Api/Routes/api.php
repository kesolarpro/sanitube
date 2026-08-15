<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use SaniTube\Api\Http\Controllers\V1\ArtistController;
use SaniTube\Api\Http\Controllers\V1\CompositionController;
use SaniTube\Api\Http\Controllers\V1\HealthController;
use SaniTube\Api\Http\Controllers\V1\IngestionBatchController;
use SaniTube\Api\Http\Controllers\V1\ReleaseController;
use SaniTube\Api\Http\Controllers\V1\TrackCandidateController;
use SaniTube\Api\Http\Controllers\V1\TrackController;
use SaniTube\Api\Http\Middleware\ThrottleHealthRequests;
use SaniTube\Api\Http\Middleware\VerifyHealthToken;
use SaniTube\Api\Http\Middleware\VerifyInternalApiToken;

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
     * Readiness and the capability report describe the environment and do run
     * every detector, so they are token-protected and throttled more tightly
     * than liveness.
     */
    Route::middleware([
        ThrottleHealthRequests::class.':privileged',
        VerifyHealthToken::class,
    ])->group(function (): void {
        Route::get('health/ready', [HealthController::class, 'ready'])->name('health.ready');
        Route::get('system/capabilities', [HealthController::class, 'capabilities'])->name('system.capabilities');
    });

    /*
     * Read-only catalogue.
     *
     * Read-only is a deliberate boundary, not a stage of completion: writes
     * carry invariants that belong in domain services, and exposing them
     * before the Identity module can say who is calling would mean an
     * unauthenticated shared token could mutate the catalogue.
     *
     * Route binding resolves on `uuid` (see HasPublicUuid), so no internal id
     * can appear in a URL.
     */
    Route::middleware(VerifyInternalApiToken::class)->group(function (): void {
        Route::get('artists', [ArtistController::class, 'index'])->name('artists.index');
        Route::get('artists/{artist}', [ArtistController::class, 'show'])->name('artists.show');

        Route::get('compositions', [CompositionController::class, 'index'])->name('compositions.index');
        Route::get('compositions/{composition}', [CompositionController::class, 'show'])->name('compositions.show');

        Route::get('tracks', [TrackController::class, 'index'])->name('tracks.index');
        Route::get('tracks/{track}', [TrackController::class, 'show'])->name('tracks.show');

        Route::get('releases', [ReleaseController::class, 'index'])->name('releases.index');
        Route::get('releases/{release}', [ReleaseController::class, 'show'])->name('releases.show');

        /*
         * Ingestion.
         *
         * The one write in v1, and it writes to the *staging* side of the
         * platform rather than the catalogue: a batch imports material and
         * produces candidates, which are proposals. Nothing here can create a
         * Track, so exposing it before the Identity module exists does not
         * hand a shared token the ability to mutate the catalogue.
         *
         * The POST carries references, never payloads. Bytes arrive through
         * the storage pipeline.
         */
        Route::post('ingestion/batches', [IngestionBatchController::class, 'store'])->name('ingestion.batches.store');
        Route::get('ingestion/batches', [IngestionBatchController::class, 'index'])->name('ingestion.batches.index');
        Route::get('ingestion/batches/{batch}', [IngestionBatchController::class, 'show'])->name('ingestion.batches.show');

        Route::get('ingestion/candidates', [TrackCandidateController::class, 'index'])->name('ingestion.candidates.index');
        Route::get('ingestion/candidates/{candidate}', [TrackCandidateController::class, 'show'])->name('ingestion.candidates.show');
    });

});
