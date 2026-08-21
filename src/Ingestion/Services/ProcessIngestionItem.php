<?php

declare(strict_types=1);

namespace SaniTube\Ingestion\Services;

use SaniTube\Assets\Enums\AssetKind;
use SaniTube\Assets\Enums\AssetStatus;
use SaniTube\Assets\Models\Asset;
use SaniTube\Assets\Services\AssetStorageService;
use SaniTube\Assets\Services\AssetVerificationService;
use SaniTube\Catalog\Models\Track;
use SaniTube\Ingestion\Enums\IngestionFailureCode;
use SaniTube\Ingestion\Enums\IngestionItemStatus;
use SaniTube\Ingestion\Enums\TrackCandidateStatus;
use SaniTube\Ingestion\Events\TrackCandidateCreated;
use SaniTube\Ingestion\Exceptions\IngestionException;
use SaniTube\Ingestion\Manifest\ManifestConflictDetector;
use SaniTube\Ingestion\Manifest\ManifestRow;
use SaniTube\Ingestion\Models\IngestionItem;
use SaniTube\Ingestion\Models\TrackCandidate;
use SaniTube\Ingestion\Sources\SourceReaderFactory;
use SaniTube\Storage\CredentialRedactor;
use Throwable;

/**
 * Moves one source into the platform.
 *
 * Every step is resumable, because the interesting failures happen in the
 * middle: a worker is killed, a provider times out, the database blinks. The
 * sequence is arranged so that repeating it after any of those converges on
 * the same asset and the same candidate rather than making new ones.
 *
 * The order matters more than it looks:
 *
 *   1. **Register the asset and record its id on the item — before uploading.**
 *      This is the pivot. AST-001 derives an asset's object key from its UUID,
 *      so an asset that already exists has exactly one address for its bytes;
 *      a retry re-uploads to that same address and overwrites a partial write
 *      instead of creating a second object. If the id were recorded after the
 *      upload, a crash in between would orphan the bytes and the retry would
 *      register a *new* asset — one abandoned object per attempt.
 *   2. **Store, then verify by reading back.** The asset workflow hashes what
 *      actually landed rather than what was sent.
 *   3. **Create the candidate, never a Track.** Importing 900 files must not
 *      put 900 rows in the catalogue.
 *
 * Nothing here deletes the source. A CLOUD_IMPORT reference belongs to whoever
 * owns that storage, and an import that quietly removes its input is an import
 * nobody dares run twice.
 */
final readonly class ProcessIngestionItem
{
    public function __construct(
        private AssetStorageService $assets,
        private AssetVerificationService $verification,
        private SourceReaderFactory $readers,
        private ManifestConflictDetector $conflicts,
    ) {}

    public function handle(IngestionItem $item): IngestionItem
    {
        if ($item->status->isTerminal()) {
            // Already settled by an earlier attempt. Re-running a job after
            // its work landed must be free, not a second import.
            return $item;
        }

        $item->forceFill([
            'status' => IngestionItemStatus::Processing,
            'attempt_count' => $item->attempt_count + 1,
        ])->save();

        try {
            $asset = $this->assetFor($item);
            $this->fill($item, $asset);

            $verified = $this->verification->verify($asset->refresh());

            if ($verified->status !== AssetStatus::Verified) {
                return $this->fail(
                    $item,
                    IngestionFailureCode::VerificationFailed,
                    sprintf('The stored object did not verify; the asset is %s.', $verified->status->value),
                );
            }

            $candidate = $this->candidateFor($item, $verified);

            $duplicate = $verified->duplicate_of_asset_id !== null;

            $item->forceFill([
                'candidate_id' => $candidate->id,
                'status' => $duplicate ? IngestionItemStatus::Duplicate : IngestionItemStatus::Imported,
                'failure_code' => null,
                'failure_message' => null,
            ])->save();

            return $item->refresh();
        } catch (IngestionException $exception) {
            return $this->fail($item, $exception->failureCode, $exception->getMessage());
        } catch (Throwable $exception) {
            return $this->fail($item, IngestionFailureCode::StorageFailure, $exception->getMessage());
        }
    }

    /**
     * The asset this item is importing into, created once and reused for
     * every subsequent attempt.
     */
    private function assetFor(IngestionItem $item): Asset
    {
        if ($item->asset_id !== null) {
            $existing = Asset::query()->find($item->asset_id);

            if ($existing instanceof Asset) {
                return $existing;
            }
        }

        $reader = $this->readers->for($item->batch->source);

        $asset = $this->assets->register(
            kind: AssetKind::AudioMaster,
            originalFilename: $reader->originalFilename($item->source_reference),
        );

        // Persisted immediately. Everything after this point is resumable
        // precisely because the asset — and therefore its object key — is
        // already decided and written down.
        $item->forceFill(['asset_id' => $asset->id])->save();

        return $asset;
    }

    /**
     * Put the bytes where the asset says they go.
     */
    private function fill(IngestionItem $item, Asset $asset): void
    {
        if ($asset->status->isImmutable()) {
            // A previous attempt already stored them. The asset workflow would
            // treat this as an idempotent retry anyway; not opening the source
            // again saves a pointless read of a large file.
            return;
        }

        $reader = $this->readers->for($item->batch->source);
        $stream = $reader->open($item->source_reference);

        try {
            $this->assets->store($asset, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * The candidate for this import — a proposal, never a Track.
     */
    private function candidateFor(IngestionItem $item, Asset $asset): TrackCandidate
    {
        if ($item->candidate_id !== null) {
            $existing = TrackCandidate::query()->find($item->candidate_id);

            if ($existing instanceof TrackCandidate) {
                return $existing;
            }
        }

        $duplicateOf = $asset->duplicate_of_asset_id;
        $manifest = $this->manifestFor($item);

        $candidate = TrackCandidate::query()->create([
            'source' => $item->batch->source,
            'asset_id' => $asset->id,
            'original_filename' => $item->original_filename,
            // A guess, and named as one. A manifest title is a better guess
            // than a filename — somebody typed it deliberately — but it is
            // still a guess, which is why it lands in the same `suggested_`
            // field and not in the catalogue. Where the title came from is
            // recorded beside it, because "the operator said so" and "we read
            // it off the file name" deserve different amounts of trust from a
            // reviewer.
            'suggested_title' => $manifest['title'] ?? SuggestTitle::from($item->original_filename),
            'matched_asset_id' => $duplicateOf,
            'matched_track_id' => $duplicateOf === null ? null : $this->trackHolding($duplicateOf),
            'metadata' => $manifest['metadata'],
            // PENDING, not READY: the bytes are verified but nothing has yet
            // looked *inside* them. MED-001 settles this to READY — or to
            // WAITING_CAPABILITY, or NEEDS_REVIEW — once analysis has had its
            // turn. Publishing a candidate as review-ready before its duration
            // and loudness are known means reviewing it without the numbers the
            // review exists to consider.
            //
            // Bytes that are already held are still a real import — recorded
            // for review rather than rejected, because the same master
            // legitimately arrives twice. That case is settled already: the
            // duplicate points at an asset the platform has analysed before.
            //
            // A manifest conflict — an ISRC the catalogue already has on
            // another track — settles the candidate as NEEDS_REVIEW here and
            // now. That is deliberate: NEEDS_REVIEW is terminal, so analysis
            // will not later settle it to READY and quietly make it
            // promotable. The asset is still analysed either way, because the
            // person who has to decide wants the duration and the loudness in
            // front of them.
            //
            // DUPLICATE takes precedence when both apply. It is the more
            // specific finding — these exact bytes are already held — and both
            // states hold the candidate for a human, with the conflict
            // recorded in `metadata` regardless.
            'status' => match (true) {
                $duplicateOf !== null => TrackCandidateStatus::Duplicate,
                $manifest['conflicts'] !== [] => TrackCandidateStatus::NeedsReview,
                default => TrackCandidateStatus::Pending,
            },
        ]);

        event(new TrackCandidateCreated($candidate));

        return $candidate;
    }

    /**
     * The manifest row for this item, turned into what the candidate records.
     *
     * Returns three things because they are decided together: the title to
     * suggest, the metadata to store, and whether anything the manifest claims
     * contradicts the catalogue.
     *
     * `metadata` is null when there was no manifest, rather than an empty
     * array. "Nobody supplied one" and "one was supplied and said nothing" are
     * different facts, and a reviewer reading an empty object would reasonably
     * conclude the second.
     *
     * @return array{title: string|null, metadata: array<string, mixed>|null, conflicts: list<array<string, mixed>>}
     */
    private function manifestFor(IngestionItem $item): array
    {
        $evidence = $item->manifest_metadata;

        if (! is_array($evidence) || $evidence === []) {
            return ['title' => null, 'metadata' => null, 'conflicts' => []];
        }

        $row = ManifestRow::fromEvidence($evidence);
        $inspection = $this->conflicts->inspect($row);

        $metadata = ['manifest' => $evidence];

        // Where the suggested title came from, stated rather than inferred.
        $metadata['suggested_title_source'] = $row->title !== null ? 'MANIFEST' : 'FILENAME';

        if ($inspection['conflicts'] !== []) {
            $metadata['manifest_conflicts'] = $inspection['conflicts'];
        }

        if ($inspection['observations'] !== []) {
            $metadata['manifest_observations'] = $inspection['observations'];
        }

        return [
            'title' => $row->title,
            'metadata' => $metadata,
            'conflicts' => $inspection['conflicts'],
        ];
    }

    /**
     * Which track, if any, already uses the asset these bytes duplicate.
     */
    private function trackHolding(int $assetId): ?int
    {
        /** @var int|null $trackId */
        $trackId = Track::query()
            ->where('master_asset_id', $assetId)
            ->value('id');

        return $trackId;
    }

    /**
     * The one place a failure is written, and the one place it is scrubbed.
     *
     * Two of the three callers hand this an exception's own words, and one of
     * those is a bare `Throwable` from the storage client. An S3 403 arrives
     * as `GET https://…?X-Amz-Signature=… resulted in a 403`, and this column
     * is durable: it survives the run, it is read by the internal API, and it
     * goes into every database backup taken afterwards. A transient leak is a
     * leak; a stored one is a leak with a copy.
     *
     * The failure *code* is what the platform acts on and is untouched — the
     * message is only ever read by a person deciding what to do next.
     */
    private function fail(IngestionItem $item, IngestionFailureCode $code, string $message): IngestionItem
    {
        $item->forceFill([
            'status' => IngestionItemStatus::Failed,
            'failure_code' => $code,
            'failure_message' => CredentialRedactor::scrub($message),
        ])->save();

        return $item->refresh();
    }
}
