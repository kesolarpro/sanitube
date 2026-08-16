<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use SaniTube\Ui\Http\Controllers\Catalog\ArtistDetailController;
use SaniTube\Ui\Http\Controllers\Catalog\ArtistIndexController;
use SaniTube\Ui\Http\Controllers\Catalog\AssetDetailController;
use SaniTube\Ui\Http\Controllers\Catalog\AssetIndexController;
use SaniTube\Ui\Http\Controllers\Catalog\AssetPreviewController;
use SaniTube\Ui\Http\Controllers\Catalog\CompositionDetailController;
use SaniTube\Ui\Http\Controllers\Catalog\CompositionIndexController;
use SaniTube\Ui\Http\Controllers\Catalog\ContributorDetailController;
use SaniTube\Ui\Http\Controllers\Catalog\ContributorIndexController;
use SaniTube\Ui\Http\Controllers\Catalog\TrackDetailController;
use SaniTube\Ui\Http\Controllers\Catalog\TrackIndexController;
use SaniTube\Ui\Http\Controllers\DashboardController;
use SaniTube\Ui\Http\Controllers\DesignSystemController;
use SaniTube\Ui\Http\Controllers\Ingestion\BatchDetailController;
use SaniTube\Ui\Http\Controllers\Ingestion\BatchIndexController;
use SaniTube\Ui\Http\Controllers\Ingestion\CandidateDetailController;
use SaniTube\Ui\Http\Controllers\Ingestion\CandidateIndexController;
use SaniTube\Ui\Http\Controllers\Ingestion\CandidateReviewController;
use SaniTube\Ui\Http\Controllers\Studio\GenerationDetailController;
use SaniTube\Ui\Http\Controllers\Studio\GenerationIndexController;
use SaniTube\Ui\Http\Controllers\Studio\OverviewController;
use SaniTube\Ui\Http\Controllers\Studio\ProjectDetailController;
use SaniTube\Ui\Http\Controllers\Studio\ProjectIndexController;
use SaniTube\Ui\Http\Controllers\Studio\StudioActionController;
use SaniTube\Ui\Http\Controllers\System\JobsController;
use SaniTube\Ui\Http\Controllers\System\OperationsController;
use SaniTube\Ui\Http\Controllers\System\RefreshHealthController;
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
    Route::get('catalog/assets', AssetIndexController::class)->name('catalog.assets');
    Route::get('catalog/assets/{asset}', AssetDetailController::class)->name('catalog.assets.show');

    // POST, not GET: minting a preview creates a bearer credential, and a GET
    // that did so would be prefetched, cached and replayed from history.
    Route::post('catalog/assets/{asset}/preview', AssetPreviewController::class)
        ->middleware('throttle:sanitube-asset-preview')
        ->name('catalog.assets.preview');

    Route::get('catalog/contributors', ContributorIndexController::class)->name('catalog.contributors');
    Route::get('catalog/contributors/{contributor}', ContributorDetailController::class)->name('catalog.contributors.show');
    Route::get('catalog/compositions', CompositionIndexController::class)->name('catalog.compositions');
    Route::get('catalog/compositions/{composition}', CompositionDetailController::class)->name('catalog.compositions.show');
    Route::get('catalog/artists', ArtistIndexController::class)->name('catalog.artists');
    Route::get('catalog/artists/{artist}', ArtistDetailController::class)->name('catalog.artists.show');
    Route::get('catalog/tracks', TrackIndexController::class)->name('catalog.tracks');
    Route::get('catalog/tracks/{track}', TrackDetailController::class)->name('catalog.tracks.show');

    // Ingestion. Read-only: these screens report what an import did, and the
    // decisions taken on what it produced. Starting an import and promoting a
    // candidate are writes and are not here.
    Route::get('ingestion/batches', BatchIndexController::class)->name('ingestion.batches');
    Route::get('ingestion/batches/{batch}', BatchDetailController::class)->name('ingestion.batches.show');
    Route::get('ingestion/candidates', CandidateIndexController::class)->name('ingestion.candidates');
    Route::get('ingestion/candidates/{candidate}', CandidateDetailController::class)->name('ingestion.candidates.show');

    /*
     * Review. The point at which a proposal becomes catalogue data, or stops
     * being a proposal at all.
     *
     * Behind `can.role:catalogue` as well as `auth` and `active`: a MEMBER may
     * read the review queue and may not decide anything in it. The check lives
     * on the route rather than in the controller, so a future route cannot
     * acquire the action without acquiring the guard.
     *
     * Named actions, never a settable status. Promotion runs invariants,
     * writes to the catalogue and is refusable for several distinct reasons;
     * `PATCH {status: PROMOTED}` would present all of that as an assignment.
     */
    Route::middleware('can.role:catalogue')->group(function (): void {
        Route::post('ingestion/candidates/{candidate}/promote', [CandidateReviewController::class, 'promote'])
            ->name('ingestion.candidates.promote');
        Route::post('ingestion/candidates/{candidate}/reject', [CandidateReviewController::class, 'reject'])
            ->name('ingestion.candidates.reject');
        Route::patch('ingestion/candidates/{candidate}', [CandidateReviewController::class, 'revise'])
            ->name('ingestion.candidates.revise');
    });

    // Studio. Read-only for now: starting a generation calls a supplier and
    // costs money, and the surface that does it needs its own ticket.
    Route::get('studio', OverviewController::class)->name('studio');
    Route::get('studio/projects', ProjectIndexController::class)->name('studio.projects');
    Route::get('studio/projects/{project}', ProjectDetailController::class)->name('studio.projects.show');
    Route::get('studio/generations', GenerationIndexController::class)->name('studio.generations');
    Route::get('studio/generations/{generation}', GenerationDetailController::class)->name('studio.generations.show');

    /*
     * Studio writes.
     *
     * Behind `can.role:catalogue`, the same guard candidate review uses.
     * Starting a generation spends the operator's money at a supplier and
     * selecting a result puts audio into the review queue; a MEMBER may watch
     * the studio and cause neither.
     */
    Route::middleware('can.role:catalogue')->group(function (): void {
        Route::post('studio/projects', [StudioActionController::class, 'createProject'])
            ->name('studio.projects.store');
        Route::post('studio/generations', [StudioActionController::class, 'startGeneration'])
            ->name('studio.generations.store');
        Route::post('studio/generations/{generation}/cancel', [StudioActionController::class, 'cancelGeneration'])
            ->name('studio.generations.cancel');
        Route::post('studio/results/{result}/select', [StudioActionController::class, 'selectResult'])
            ->name('studio.results.select');
    });

    /*
     * System.
     *
     * Both GETs read stored state and probe nothing: this is what somebody
     * opens during an outage. Behind `can.role:administer` because a queue
     * listing says what the installation is doing and how it is configured.
     */
    Route::middleware('can.role:administer')->group(function (): void {
        Route::get('system/operations', OperationsController::class)->name('system.operations');
        Route::get('system/jobs', JobsController::class)->name('system.jobs');

        // The one place a probe may run from a request: explicit, POST, and
        // pressed by a person who asked for it.
        Route::post('system/operations/refresh', RefreshHealthController::class)
            ->name('system.operations.refresh');
    });

    Route::get('design-system', DesignSystemController::class)->name('design-system');
});
