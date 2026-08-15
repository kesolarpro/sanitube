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

    Route::get('design-system', DesignSystemController::class)->name('design-system');
});
