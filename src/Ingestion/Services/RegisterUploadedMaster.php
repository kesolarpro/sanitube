<?php

declare(strict_types=1);

namespace SaniTube\Ingestion\Services;

use SaniTube\Assets\Enums\AssetKind;
use SaniTube\Assets\Enums\AssetStatus;
use SaniTube\Assets\Models\Asset;
use SaniTube\Catalog\Models\Track;
use SaniTube\Ingestion\Enums\IngestionSource;
use SaniTube\Ingestion\Enums\TrackCandidateStatus;
use SaniTube\Ingestion\Events\TrackCandidateCreated;
use SaniTube\Ingestion\Models\TrackCandidate;

/**
 * The bridge a file uploaded from a browser had no way across.
 *
 * UPL-003. A `TrackCandidate` — and therefore a `Track` — could only ever be
 * created two ways: by `ProcessIngestionItem`, which reads from a source an
 * operator points the server at, and by `SelectMusicGenerationResult`. UPL-001
 * gave the platform its first `<input type="file">`, and the bytes it accepted
 * arrived, were verified, and then stopped. There was no route from an uploaded
 * master to a track at all, so the journey the product exists for ended one
 * step after it began.
 *
 * **A candidate, never a Track.** The same rule the importer follows, for the
 * same reason: the catalogue is what somebody decided, and a row that appears
 * in it because a file was uploaded is a row nobody decided. Review, promotion
 * and I3 are unchanged and already built — this only gets the file into the
 * queue they operate on.
 *
 * **Idempotent on the asset.** The one failure that matters here. An upload
 * screen retries, a person presses the button twice, a direct upload's
 * completion call is repeated after a timeout — and a second candidate for the
 * same asset would put one recording in the review queue twice, where promoting
 * both would put it in the catalogue twice.
 *
 * **Verified only.** A candidate pointing at an unverified master is a
 * candidate nobody can promote, which is a queue entry that exists to be stuck.
 *
 * **`MANUAL_UPLOAD`, and it means exactly what it says.** A person uploaded
 * this. That the provenance value predates this ticket is not a coincidence to
 * work around: `IngestionSource` warns in its own docblock that adding a case
 * to a populated enum is a migration rather than a line of code, and there is
 * no distinction here worth one.
 */
final readonly class RegisterUploadedMaster
{
    /**
     * The candidate for this upload, or null when the asset is not one.
     *
     * Null rather than an exception, because the two "not one" cases — artwork,
     * and a master that failed verification — are both ordinary outcomes of an
     * upload rather than faults. The caller reports what happened; nothing here
     * decides that an upload went wrong.
     */
    public function handle(Asset $asset): ?TrackCandidate
    {
        if ($asset->kind !== AssetKind::AudioMaster || $asset->status !== AssetStatus::Verified) {
            return null;
        }

        $existing = TrackCandidate::query()->where('asset_id', $asset->getKey())->first();

        if ($existing instanceof TrackCandidate) {
            return $existing;
        }

        $duplicateOf = $asset->duplicate_of_asset_id;

        $candidate = TrackCandidate::query()->create([
            'source' => IngestionSource::ManualUpload,
            'asset_id' => $asset->getKey(),
            'original_filename' => $asset->original_filename,

            // A guess, and named as one — exactly as an imported file's is.
            // A filename is not a title; it is the best thing available when
            // nobody has said what this recording is called, and it lands in
            // `suggested_` rather than in the catalogue for that reason.
            'suggested_title' => SuggestTitle::from((string) $asset->original_filename),

            'matched_asset_id' => $duplicateOf,
            'matched_track_id' => $duplicateOf === null ? null : $this->trackHolding($duplicateOf),

            // PENDING, not READY: the bytes are verified but nothing has yet
            // looked *inside* them. MED-001 settles this once analysis has had
            // its turn. Uploaded audio gets no shortcut over imported audio.
            //
            // Bytes already held are still a real upload — recorded for review
            // rather than refused, because the same master legitimately arrives
            // twice, and the reviewer is told which asset and which track
            // already hold them.
            'status' => $duplicateOf === null
                ? TrackCandidateStatus::Pending
                : TrackCandidateStatus::Duplicate,
        ]);

        // The same event the importer raises, so MED-001's analysis and
        // everything else listening treat an uploaded master exactly as they
        // treat an imported one.
        event(new TrackCandidateCreated($candidate));

        return $candidate;
    }

    private function trackHolding(int $assetId): ?int
    {
        /** @var int|null $trackId */
        $trackId = Track::query()
            ->where('master_asset_id', $assetId)
            ->value('id');

        return $trackId;
    }
}
