<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use SaniTube\Api\Http\Controllers\V1\ArtistController;
use SaniTube\Api\Http\Controllers\V1\CompositionController;
use SaniTube\Api\Http\Controllers\V1\GenerationProjectController;
use SaniTube\Api\Http\Controllers\V1\HealthController;
use SaniTube\Api\Http\Controllers\V1\IngestionBatchController;
use SaniTube\Api\Http\Controllers\V1\MusicGenerationController;
use SaniTube\Api\Http\Controllers\V1\ReleaseBuilderController;
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

        /*
         * Review — the point at which a proposal becomes catalogue data, or
         * stops being a proposal at all.
         *
         * `promote` is the first and only route in v1 that creates a Track,
         * and it is a POST to a named action rather than a PATCH of `status`.
         * Promotion runs invariants, writes to the catalogue and is refusable
         * for several distinct reasons; a settable status field would present
         * all of that as an assignment.
         *
         * The read-only boundary above still holds for the catalogue itself:
         * nothing here edits an existing Track.
         */
        Route::patch('ingestion/candidates/{candidate}', [TrackCandidateController::class, 'update'])
            ->name('ingestion.candidates.update');
        Route::post('ingestion/candidates/{candidate}/promote', [TrackCandidateController::class, 'promote'])
            ->name('ingestion.candidates.promote');
        Route::post('ingestion/candidates/{candidate}/reject', [TrackCandidateController::class, 'reject'])
            ->name('ingestion.candidates.reject');

        /*
         * Music generation — the Studio's surface.
         *
         * Everything here creates or moves a *generation*. Selecting a result
         * produces a TrackCandidate, never a Track, so a campaign that runs
         * overnight cannot write nine hundred rows into the catalogue: each
         * one still goes through the same review CAT-001 defines.
         *
         * No provider credential, endpoint or job identifier crosses this
         * boundary in either direction.
         */
        Route::post('generation/projects', [GenerationProjectController::class, 'store'])
            ->name('generation.projects.store');
        Route::get('generation/projects', [GenerationProjectController::class, 'index'])
            ->name('generation.projects.index');
        Route::get('generation/projects/{project}', [GenerationProjectController::class, 'show'])
            ->name('generation.projects.show');

        Route::post('generations', [MusicGenerationController::class, 'store'])
            ->name('generations.store');
        Route::get('generations/{generation}', [MusicGenerationController::class, 'show'])
            ->name('generations.show');
        Route::post('generations/{generation}/cancel', [MusicGenerationController::class, 'cancel'])
            ->name('generations.cancel');
        Route::get('generations/{generation}/results', [MusicGenerationController::class, 'results'])
            ->name('generations.results');
        Route::post('generation/results/{result}/select', [MusicGenerationController::class, 'select'])
            ->name('generation.results.select');
        /*
         * The Release Builder.
         *
         * `validate` and `ready` are separate on purpose: a label wants to see
         * what is missing while it is still assembling, and committing is a
         * distinct act that can fail. Nothing here sets `status` directly —
         * readiness is earned by passing validation, not assigned, and a
         * settable status field would make I4 optional.
         *
         * Every mutation goes through ReleaseMutationPolicy, so a release a
         * distributor already has cannot be edited into disagreeing with what
         * stores are serving.
         */
        Route::post('releases', [ReleaseBuilderController::class, 'store'])->name('releases.store');
        Route::patch('releases/{release}', [ReleaseBuilderController::class, 'update'])->name('releases.update');

        Route::post('releases/{release}/tracks', [ReleaseBuilderController::class, 'addTrack'])
            ->name('releases.tracks.store');
        Route::delete('releases/{release}/tracks/{track}', [ReleaseBuilderController::class, 'removeTrack'])
            ->name('releases.tracks.destroy');
        Route::post('releases/{release}/tracks/reorder', [ReleaseBuilderController::class, 'reorder'])
            ->name('releases.tracks.reorder');

        Route::post('releases/{release}/validate', [ReleaseBuilderController::class, 'validateRelease'])
            ->name('releases.validate');
        Route::post('releases/{release}/ready', [ReleaseBuilderController::class, 'ready'])
            ->name('releases.ready');
        Route::post('releases/{release}/reopen', [ReleaseBuilderController::class, 'reopen'])
            ->name('releases.reopen');
    });

});
