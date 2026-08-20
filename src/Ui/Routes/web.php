<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use SaniTube\Ui\Http\Controllers\Assets\DirectUploadController;
use SaniTube\Ui\Http\Controllers\Catalog\ArtistDetailController;
use SaniTube\Ui\Http\Controllers\Catalog\ArtistIndexController;
use SaniTube\Ui\Http\Controllers\Catalog\AssetDetailController;
use SaniTube\Ui\Http\Controllers\Catalog\AssetIndexController;
use SaniTube\Ui\Http\Controllers\Catalog\AssetPreviewController;
use SaniTube\Ui\Http\Controllers\Catalog\CompositionDetailController;
use SaniTube\Ui\Http\Controllers\Catalog\CompositionIndexController;
use SaniTube\Ui\Http\Controllers\Catalog\ContributorDetailController;
use SaniTube\Ui\Http\Controllers\Catalog\ContributorIndexController;
use SaniTube\Ui\Http\Controllers\Catalog\TrackActionController;
use SaniTube\Ui\Http\Controllers\Catalog\TrackDetailController;
use SaniTube\Ui\Http\Controllers\Catalog\TrackIndexController;
use SaniTube\Ui\Http\Controllers\DashboardController;
use SaniTube\Ui\Http\Controllers\Deduplication\DuplicateActionController;
use SaniTube\Ui\Http\Controllers\Deduplication\DuplicateIndexController;
use SaniTube\Ui\Http\Controllers\DesignSystemController;
use SaniTube\Ui\Http\Controllers\Distribution\DeliveryDetailController;
use SaniTube\Ui\Http\Controllers\Distribution\DeliveryIndexController;
use SaniTube\Ui\Http\Controllers\Distribution\DistributionActionController;
use SaniTube\Ui\Http\Controllers\Distribution\ReleaseDistributionController;
use SaniTube\Ui\Http\Controllers\Enrichment\EnrichmentRequestController;
use SaniTube\Ui\Http\Controllers\Enrichment\SuggestionActionController;
use SaniTube\Ui\Http\Controllers\Enrichment\SuggestionIndexController;
use SaniTube\Ui\Http\Controllers\Ingestion\BatchDetailController;
use SaniTube\Ui\Http\Controllers\Ingestion\BatchIndexController;
use SaniTube\Ui\Http\Controllers\Ingestion\CandidateDetailController;
use SaniTube\Ui\Http\Controllers\Ingestion\CandidateIndexController;
use SaniTube\Ui\Http\Controllers\Ingestion\CandidateReviewController;
use SaniTube\Ui\Http\Controllers\Ingestion\ImportActionController;
use SaniTube\Ui\Http\Controllers\Releases\ReleaseActionController;
use SaniTube\Ui\Http\Controllers\Releases\ReleaseBuilderController;
use SaniTube\Ui\Http\Controllers\Releases\ReleaseIndexController;
use SaniTube\Ui\Http\Controllers\Releases\ReleasePickerController;
use SaniTube\Ui\Http\Controllers\Settings\SettingsController;
use SaniTube\Ui\Http\Controllers\Settings\SettingsUpdateController;
use SaniTube\Ui\Http\Controllers\Studio\GenerationDetailController;
use SaniTube\Ui\Http\Controllers\Studio\GenerationIndexController;
use SaniTube\Ui\Http\Controllers\Studio\OverviewController;
use SaniTube\Ui\Http\Controllers\Studio\ProjectDetailController;
use SaniTube\Ui\Http\Controllers\Studio\ProjectIndexController;
use SaniTube\Ui\Http\Controllers\Studio\StudioActionController;
use SaniTube\Ui\Http\Controllers\System\AuditController;
use SaniTube\Ui\Http\Controllers\System\FailedJobController;
use SaniTube\Ui\Http\Controllers\System\JobsController;
use SaniTube\Ui\Http\Controllers\System\OperationsController;
use SaniTube\Ui\Http\Controllers\System\RefreshHealthController;
use SaniTube\Ui\Http\Controllers\Transcription\TranscriptionRequestController;
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

    /*
     * Catalogue writes.
     *
     * CAT-002, and the reason it exists is worth writing down: everything
     * above this line is a read, and for a while everything in the catalogue
     * was. Promotion creates a DRAFT track, I3 refuses READY without a primary
     * artist, and nothing gave a person a way to supply one — so a label who
     * imported a library could promote a candidate and then stop, with every
     * release built from those tracks failing validation on an issue whose fix
     * was not reachable from any screen.
     *
     * Behind `can.role:catalogue`, the same guard candidate review uses: a
     * MEMBER may read the catalogue and may not credit or promote anything in
     * it. On the route rather than in the controller, so a future action
     * cannot acquire the surface without acquiring the guard.
     *
     * Named actions, never a settable status. `PATCH {status: READY}` would
     * present I3 as an assignment.
     */
    Route::middleware('can.role:catalogue')->group(function (): void {
        /*
         * STO-003. Uploading a master without it passing through PHP.
         *
         * Two POSTs, both writes: the first mints a capability, the second
         * asks the server to measure what arrived. A GET for either would be
         * prefetched and replayed.
         */
        Route::post('assets/uploads', [DirectUploadController::class, 'begin'])
            ->name('assets.uploads.begin');
        Route::post('assets/uploads/{asset}/complete', [DirectUploadController::class, 'complete'])
            ->name('assets.uploads.complete');

        Route::post('catalog/tracks/{track}/credits', [TrackActionController::class, 'setCredits'])
            ->name('catalog.tracks.credits');
        Route::post('catalog/tracks/{track}/ready', [TrackActionController::class, 'markReady'])
            ->name('catalog.tracks.ready');
    });

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
        // ING-002. Starting an import is a write, and until now the only way
        // to perform one was a terminal — which meant a label that dropped
        // twenty new masters in a folder needed SSH to bring them in. Nothing
        // is imported inside the request: the batch is created and the work is
        // queued.
        Route::post('ingestion/batches', [ImportActionController::class, 'start'])
            ->name('ingestion.batches.store');

        Route::post('ingestion/candidates/{candidate}/promote', [CandidateReviewController::class, 'promote'])
            ->name('ingestion.candidates.promote');
        Route::post('ingestion/candidates/{candidate}/reject', [CandidateReviewController::class, 'reject'])
            ->name('ingestion.candidates.reject');
        Route::patch('ingestion/candidates/{candidate}', [CandidateReviewController::class, 'revise'])
            ->name('ingestion.candidates.revise');
    });

    /*
     * The duplicate queue. DEDUP-003.
     *
     * Readable by anyone signed in -- knowing that two files may hold the same
     * recording is not a privilege -- and answerable only behind
     * `can.role:catalogue`, the same guard candidate review uses.
     *
     * Deciding and trashing are separate routes because they are separate
     * acts. Confirming a duplicate is not a decision to lose a file, and a
     * single endpoint that did both would quietly make it one.
     */
    Route::get('duplicates', DuplicateIndexController::class)->name('duplicates');

    Route::middleware('can.role:catalogue')->group(function (): void {
        Route::post('duplicates/{relation}/confirm', [DuplicateActionController::class, 'confirm'])
            ->name('duplicates.confirm');
        Route::post('duplicates/{relation}/reject', [DuplicateActionController::class, 'reject'])
            ->name('duplicates.reject');

        // Keyed by the asset, not by the finding. A master is set aside
        // because somebody decided to set *it* aside; routing that through a
        // finding would tie the act to the row that prompted it and make the
        // same file untrashable from anywhere else.
        Route::post('assets/{asset}/trash', [DuplicateActionController::class, 'trash'])
            ->name('assets.trash');
        Route::post('assets/{asset}/restore', [DuplicateActionController::class, 'restore'])
            ->name('assets.restore');
    });

    /*
     * Transcription. TRN-003.
     *
     * Behind `can.role:catalogue` because this one spends money. Reading a
     * transcript is not a privilege; causing a paid call to an external
     * supplier is, and the guard sits on the route so a future action cannot
     * acquire the surface without acquiring it.
     *
     * A POST, and named for the act rather than for a status. `PATCH {status:
     * TRANSCRIBED}` would present a request to a third party as an assignment,
     * and a GET would be prefetched by a browser and billed for the privilege.
     *
     * Nothing is transcribed inside the request. The supplier round-trip runs
     * on a queue; this endpoint asks, and answers with a refusal code or
     * nothing.
     */
    /*
     * The enrichment review queue. ENR-005.
     *
     * Readable by anyone signed in -- knowing what a model proposed about a
     * recording is not a privilege. Acting on it is, and those routes below
     * carry the guard.
     */
    Route::get('enrichment/suggestions', SuggestionIndexController::class)->name('enrichment.suggestions');

    Route::middleware('can.role:catalogue')->group(function (): void {
        Route::post('assets/{asset}/transcription', TranscriptionRequestController::class)
            ->name('assets.transcription');

        /*
         * ENR-002. Asking a model to describe a recording.
         *
         * The same guard and the same reasoning as transcription above, and
         * the same act performed twice on one asset: this is the second paid
         * call, reading the evidence the first one produced.
         *
         * What comes back is a SUGGESTION and never catalogue data. Accepting
         * one is a separate act by a person through the ordinary domain
         * services, and it is not this route.
         */
        Route::post('assets/{asset}/enrichment', EnrichmentRequestController::class)
            ->name('assets.enrichment');

        /*
         * ENR-004. Answering what a model proposed.
         *
         * Three routes because they are three acts. Accepting writes
         * catalogue data through the catalogue's own editor; rejecting records
         * that a person disagreed, which is the signal a bad prompt version
         * shows up in; regenerating is neither, and routing it through a
         * rejection would record a verdict nobody gave.
         *
         * Regeneration is keyed by the asset rather than by the suggestion,
         * for the reason trashing is: what is being asked for is a new
         * suggestion about this recording, and tying it to the row that
         * prompted it would make the same recording un-regenerable from
         * anywhere else.
         */
        Route::post('enrichment/suggestions/{suggestion}/accept', [SuggestionActionController::class, 'accept'])
            ->name('enrichment.suggestions.accept');
        Route::post('enrichment/suggestions/{suggestion}/reject', [SuggestionActionController::class, 'reject'])
            ->name('enrichment.suggestions.reject');
        Route::post('assets/{asset}/enrichment/regenerate', [SuggestionActionController::class, 'regenerate'])
            ->name('assets.enrichment.regenerate');
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

        // AUDIT-001. Reading the log is not itself an event: a log that
        // records its own readers grows by being looked at.
        Route::get('system/audit', AuditController::class)->name('system.audit');

        /*
         * SET-002. The only path from a browser to a .env file.
         *
         * Behind the same role as the rest of this group, and deliberately a
         * PATCH rather than a POST: it changes some of an existing thing.
         * What may be written is WritableSettings' list and nothing else.
         */
        Route::patch('settings', SettingsUpdateController::class)->name('settings.update');

        // The one place a probe may run from a request: explicit, POST, and
        // pressed by a person who asked for it.
        Route::post('system/operations/refresh', RefreshHealthController::class)
            ->name('system.operations.refresh');

        /*
         * SYS-001b. Acting on a job that failed.
         *
         * Identified by the failed job's uuid, never by the `failed_jobs` row
         * id — a counter somebody can walk. Which jobs may be run again is
         * `ResolveFailedJob`'s decision and is asked of the job's own type;
         * nothing here re-implements it.
         */
        Route::post('system/jobs/failed/{uuid}/retry', [FailedJobController::class, 'retry'])
            ->name('system.jobs.retry');
        Route::post('system/jobs/failed/{uuid}/forget', [FailedJobController::class, 'forget'])
            ->name('system.jobs.forget');
    });

    /*
     * Releases.
     *
     * Reading is open to anyone signed in; every mutation is behind
     * `can.role:catalogue` and goes through ReleaseBuilder. Nothing here writes
     * `status` — readiness is earned by passing validation, and `markReady()`
     * re-runs the invariants rather than trusting the caller.
     */
    Route::get('releases', ReleaseIndexController::class)->name('releases');
    Route::get('releases/{release}', ReleaseBuilderController::class)->name('releases.show');

    Route::middleware('can.role:catalogue')->group(function (): void {
        Route::post('releases', [ReleaseActionController::class, 'store'])->name('releases.store');
        Route::patch('releases/{release}', [ReleaseActionController::class, 'updateDetails'])->name('releases.update');
        Route::post('releases/{release}/tracks', [ReleaseActionController::class, 'addTrack'])->name('releases.tracks.add');
        Route::delete('releases/{release}/tracks', [ReleaseActionController::class, 'removeTrack'])->name('releases.tracks.remove');
        Route::post('releases/{release}/tracks/order', [ReleaseActionController::class, 'reorder'])->name('releases.tracks.order');
        Route::post('releases/{release}/artists', [ReleaseActionController::class, 'setArtists'])->name('releases.artists');
        Route::post('releases/{release}/cover', [ReleaseActionController::class, 'setCover'])->name('releases.cover');
        Route::post('releases/{release}/ready', [ReleaseActionController::class, 'markReady'])->name('releases.ready');
        Route::post('releases/{release}/reopen', [ReleaseActionController::class, 'reopen'])->name('releases.reopen');

        /*
         * The builder's pickers. JSON, session-authenticated, read-only, and
         * behind the write role because they exist to fill in editing forms.
         *
         * `releases/options/...` rather than `releases/artists`: a two-segment
         * path would be matched by `releases/{release}` above and answered with
         * a 404 from model binding instead of the list.
         */
        Route::get('releases/options/artists', [ReleasePickerController::class, 'artists'])
            ->name('releases.options.artists');
        Route::get('releases/options/artwork', [ReleasePickerController::class, 'artwork'])
            ->name('releases.options.artwork');
        Route::get('releases/{release}/options/tracks', [ReleasePickerController::class, 'tracks'])
            ->name('releases.options.tracks');
    });

    /*
     * Distribution.
     *
     * Reading is open to anyone signed in; every action is behind
     * `can.role:distribute` — the one ability that is not simply "can write",
     * because submission is the only irreversible act in the platform.
     *
     * Nothing here reimplements DIST-001. Whether a delivery may be submitted,
     * what an outage means and what a status permits are SubmitDelivery's
     * answers.
     */
    Route::get('distribution', DeliveryIndexController::class)->name('distribution');
    Route::get('distribution/{delivery}', DeliveryDetailController::class)->name('distribution.show');
    Route::get('releases/{release}/distribution', ReleaseDistributionController::class)
        ->name('releases.distribution');

    Route::middleware('can.role:distribute')->group(function (): void {
        // Read-only preflight, answered as JSON. A GET because it changes
        // nothing — a "let me check" that left a DRAFT delivery behind would
        // become a record somebody later reads as an intention — and JSON
        // because a verdict belongs beside the destination it is about rather
        // than on a page of its own.
        Route::get('releases/{release}/distribution/{provider}/verdict', [DistributionActionController::class, 'verdict'])
            ->name('distribution.verdict');

        Route::post('releases/{release}/distribution/{provider}/submit', [DistributionActionController::class, 'submit'])
            ->name('distribution.submit');

        Route::post('distribution/{delivery}/sync', [DistributionActionController::class, 'sync'])
            ->name('distribution.sync');

        Route::post('distribution/{delivery}/takedown', [DistributionActionController::class, 'requestTakedown'])
            ->name('distribution.takedown');

        // DIST-001-H1. Neither of these is a retry: one asks the distributor
        // what it holds, the other records what a person found.
        Route::post('distribution/{delivery}/reconcile', [DistributionActionController::class, 'reconcile'])
            ->name('distribution.reconcile');
        Route::post('distribution/{delivery}/resolve', [DistributionActionController::class, 'resolve'])
            ->name('distribution.resolve');
    });

    /*
     * Settings.
     *
     * Administrator-only, and read-only. It publishes no credential value —
     * only whether one is present — but the list of which are missing is
     * itself a map of where the soft spots are.
     */
    Route::middleware('can.role:administer')->group(function (): void {
        Route::get('settings', SettingsController::class)->name('settings');
    });

    Route::get('design-system', DesignSystemController::class)->name('design-system');
});
