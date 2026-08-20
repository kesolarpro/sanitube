<?php

declare(strict_types=1);

namespace SaniTube\Deduplication\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use SaniTube\Assets\Enums\AssetStatus;
use SaniTube\Assets\Models\Asset;
use SaniTube\Deduplication\Enums\DuplicateBasis;
use SaniTube\Deduplication\Enums\DuplicateDecision;
use SaniTube\Deduplication\Enums\DuplicateLevel;
use SaniTube\Deduplication\Models\DuplicateRelation;
use SaniTube\Media\Fingerprints\FingerprintComparator;
use SaniTube\Media\Models\AudioFingerprint;
use SaniTube\Media\Services\FindFingerprintCandidates;

/**
 * Works out what an asset might be a copy of, and writes down why.
 *
 * **This is where the deciding happens, and it happens here on purpose.** The
 * media worker measures — it decodes audio and produces fingerprints — and a
 * measurement is the same number whoever asked for it. Whether 91% agreement
 * means "the same recording" is a judgement about this catalogue, and putting
 * that judgement in the worker would mean two installations with different
 * tolerances need two different workers.
 *
 * Two routes, and they are not alternatives:
 *
 *   - **Checksum.** Identical bytes. Observed, free, and always run, even with
 *     acoustic evaluation switched off.
 *   - **Fingerprint.** Different bytes, compared acoustically against the
 *     handful of assets whose duration makes them worth comparing at all.
 *
 * A pair found both ways gets one finding for each, because a reviewer told
 * only "these match acoustically" would not know the bytes were identical, and
 * a reviewer told only about the bytes would not know how the audio compared.
 *
 * **Nothing here deletes, merges, or resolves anything.** It writes findings
 * with `PROPOSED` against them and stops. A person decides, and moving a
 * master anywhere is a separate act with its own record.
 */
final readonly class EvaluateAssetDuplicates
{
    public function __construct(
        private FindFingerprintCandidates $candidates,
        private FingerprintComparator $comparator,
    ) {}

    /**
     * @return Collection<int, DuplicateRelation>
     */
    public function for(Asset $asset): Collection
    {
        // An asset with no measured checksum has no bytes the platform has
        // read back, so there is nothing to compare and nothing to say.
        if (! $asset->status->isImmutable() || $asset->sha256 === null) {
            return new Collection;
        }

        /** @var Collection<int, DuplicateRelation> $findings */
        $findings = new Collection;

        foreach ($this->identicalBytes($asset) as $match) {
            $relation = $this->record($asset, $match, DuplicateLevel::ExactDuplicate, DuplicateBasis::Checksum);

            if ($relation instanceof DuplicateRelation) {
                $findings->push($relation);
            }
        }

        foreach ($this->acousticMatches($asset) as $relation) {
            $findings->push($relation);
        }

        return $findings;
    }

    /**
     * Assets already holding these exact bytes.
     *
     * @return Collection<int, Asset>
     */
    private function identicalBytes(Asset $asset): Collection
    {
        return Asset::query()
            ->where('sha256', $asset->sha256)
            ->whereIn('status', [AssetStatus::Stored->value, AssetStatus::Verified->value])
            ->whereKeyNot($asset->getKey())
            ->orderBy('id')
            ->get();
    }

    /**
     * Assets whose audio agrees with this one closely enough to say so.
     *
     * @return Collection<int, DuplicateRelation>
     */
    private function acousticMatches(Asset $asset): Collection
    {
        /** @var Collection<int, DuplicateRelation> $found */
        $found = new Collection;

        if (! (bool) config('deduplication.enabled', true)) {
            return $found;
        }

        $fingerprint = $this->fingerprintOf($asset);

        // No fingerprint is not evidence of difference. Chromaprint is absent
        // on most shared hosting, and the honest answer there is that the
        // platform has not compared these files -- not that they differ.
        if (! $fingerprint instanceof AudioFingerprint) {
            return $found;
        }

        $sameRecording = (float) config('deduplication.thresholds.same_recording', 0.90);
        $possibleRelation = (float) config('deduplication.thresholds.possible_relation', 0.75);

        // Eager loaded: the loop asks every candidate for its asset, and the
        // candidate list is deliberately the size where a query per row is
        // invisible in tests and obvious in production.
        foreach ($this->candidates->for($fingerprint)->load('asset') as $candidate) {
            $related = $candidate->asset;

            if (! $related instanceof Asset) {
                continue;
            }

            $similarity = $this->comparator->compare($fingerprint->fingerprint, $candidate->fingerprint);

            if (! $similarity->comparable) {
                continue;
            }

            $level = match (true) {
                $similarity->similarity >= $sameRecording => DuplicateLevel::SameRecording,
                $similarity->similarity >= $possibleRelation => DuplicateLevel::PossibleRelation,
                default => null,
            };

            if ($level === null) {
                continue;
            }

            $relation = $this->record(
                $asset,
                $related,
                $level,
                DuplicateBasis::Fingerprint,
                $similarity->basisPoints(),
                $similarity->offsetFrames,
                $similarity->overlapFrames,
                $fingerprint->algorithm,
            );

            if ($relation instanceof DuplicateRelation) {
                $found->push($relation);
            }
        }

        return $found;
    }

    /**
     * The asset's most recent fingerprint.
     *
     * Most recent rather than newest-of-a-named-version, because the version
     * filtering happens one step later and more precisely: the candidate
     * search matches on this fingerprint's own algorithm, so whatever version
     * this row carries, it is only ever compared against rows of the same one.
     * Fingerprints from different versions are not reliably comparable, and
     * comparing them anyway yields a confident number computed over two
     * different things.
     */
    private function fingerprintOf(Asset $asset): ?AudioFingerprint
    {
        return AudioFingerprint::query()
            ->where('asset_id', $asset->getKey())
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Write a finding down, without disturbing one a person has answered.
     */
    private function record(
        Asset $asset,
        Asset $related,
        DuplicateLevel $level,
        DuplicateBasis $basis,
        ?int $similarityBasisPoints = null,
        ?int $offsetFrames = null,
        ?int $overlapFrames = null,
        ?string $algorithm = null,
    ): ?DuplicateRelation {
        if ($asset->getKey() === $related->getKey()) {
            return null;
        }

        // The same pair found from the other side. Direction carries meaning
        // -- which of the two arrived second -- but the pair is one thing to
        // review, and listing it twice is how a queue teaches people to skim.
        $reverse = DuplicateRelation::query()
            ->where('asset_id', $related->getKey())
            ->where('related_asset_id', $asset->getKey())
            ->where('basis', $basis->value)
            ->first();

        if ($reverse instanceof DuplicateRelation) {
            return null;
        }

        $existing = DuplicateRelation::query()
            ->where('asset_id', $asset->getKey())
            ->where('related_asset_id', $related->getKey())
            ->where('basis', $basis->value)
            ->first();

        if ($existing instanceof DuplicateRelation) {
            // Answered already. Refreshing the evidence under a decision would
            // leave a verdict beside numbers nobody decided on.
            if ($existing->decision->isDecided()) {
                return $existing;
            }

            $existing->forceFill([
                'level' => $level,
                'similarity_basis_points' => $similarityBasisPoints,
                'offset_frames' => $offsetFrames,
                'overlap_frames' => $overlapFrames,
                'algorithm' => $algorithm,
                'detected_at' => Carbon::now(),
            ])->save();

            return $existing->refresh();
        }

        return DuplicateRelation::query()->create([
            'asset_id' => $asset->getKey(),
            'related_asset_id' => $related->getKey(),
            'level' => $level,
            'basis' => $basis,
            'decision' => DuplicateDecision::Proposed,
            'similarity_basis_points' => $similarityBasisPoints,
            'offset_frames' => $offsetFrames,
            'overlap_frames' => $overlapFrames,
            'algorithm' => $algorithm,
            'detected_at' => Carbon::now(),
        ]);
    }
}
