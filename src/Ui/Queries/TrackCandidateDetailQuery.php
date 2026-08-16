<?php

declare(strict_types=1);

namespace SaniTube\Ui\Queries;

use App\Models\User;
use SaniTube\Ingestion\Enums\TrackCandidateStatus;
use SaniTube\Ingestion\Models\TrackCandidate;
use SaniTube\Media\Models\AudioAnalysis;
use SaniTube\Ui\Assets\AssetPreviewPolicy;

/**
 * Everything a person needs to decide whether one file belongs in the
 * catalogue.
 *
 * A review surface that shows only a filename is a surface on which every
 * decision is made blind, so this carries four kinds of evidence and keeps
 * them visibly separate:
 *
 *   - **What the file is** — the measurements MED-001 took. Facts.
 *   - **What the operator said** — the manifest row. A claim.
 *   - **What the platform noticed** — duplicate bytes, an ISRC already in use.
 *     Findings.
 *   - **What a person already decided** — the review note and timestamp.
 *
 * Keeping them apart is the whole design. A screen that merges the manifest's
 * title with the analyser's duration into one "metadata" block invites a
 * reviewer to accord them the same confidence, and they have earned very
 * different amounts.
 *
 * As on every other screen, the asset is described and never located, and no
 * signed URL appears in the payload — only whether one could be minted.
 */
final readonly class TrackCandidateDetailQuery
{
    public function __construct(private AssetPreviewPolicy $policy) {}

    /**
     * @return array<string, mixed>
     */
    public function forCandidate(TrackCandidate $candidate, User $viewer): array
    {
        $candidate->load(['asset', 'matchedAsset', 'matchedTrack', 'promotedTrack']);

        $metadata = is_array($candidate->metadata) ? $candidate->metadata : [];
        $asset = $candidate->asset;

        return [
            'uuid' => $candidate->uuid,
            'status' => $candidate->status->value,
            'source' => $candidate->source->value,
            'original_filename' => $candidate->original_filename,
            'suggested_title' => $candidate->suggested_title,

            // Which of the two the suggestion came from. "The operator typed
            // it" and "we parsed it out of the file name" deserve different
            // amounts of trust, and a screen that does not say which it has
            // gets both wrong.
            'suggested_title_source' => is_string($metadata['suggested_title_source'] ?? null)
                ? $metadata['suggested_title_source']
                : null,

            'failure_code' => $candidate->failure_code?->value,

            'asset' => $asset === null ? null : [
                'uuid' => $asset->uuid,
                'kind' => $asset->kind->value,
                'status' => $asset->status->value,
                'mime_type' => $asset->mime_type,
                'byte_size' => $asset->byte_size,
                'checksum_short' => substr((string) $asset->sha256, 0, 12),
                'duration_ms' => $asset->duration_ms,
                'preview' => $this->preview($candidate, $viewer),
            ],

            'analysis' => $this->analysis($candidate),
            'duplicate' => $this->duplicate($candidate),
            'manifest' => $this->manifest($metadata),
            'actions' => $this->actions($candidate, $viewer),
            'review' => [
                'reviewed_at' => $candidate->reviewed_at?->toAtomString(),
                'note' => $candidate->review_note,
                'promoted_track' => $candidate->promotedTrack === null ? null : [
                    'uuid' => $candidate->promotedTrack->uuid,
                    'title' => $candidate->promotedTrack->title,
                ],
            ],

            'created_at' => $candidate->created_at?->toAtomString(),
            'updated_at' => $candidate->updated_at?->toAtomString(),
        ];
    }

    /**
     * Which decisions this candidate is in a state to receive, and whether
     * this person may take them.
     *
     * **Presentation, never authorisation.** The route's `can.role:catalogue`
     * middleware decides who may act, and the domain services decide what is
     * permissible; this exists so the interface can render a disabled button
     * with an explanation instead of a button that fails. Removing every one
     * of these flags would change what the screen looks like and change
     * nothing about what a request is allowed to do — which is the property
     * that makes it safe to compute here at all.
     *
     * The status predicates are the enum's own, not a second copy of them. If
     * `isPromotable()` ever changes meaning, this changes with it.
     *
     * @return array<string, mixed>
     */
    private function actions(TrackCandidate $candidate, User $viewer): array
    {
        $mayWrite = $viewer->role->canWriteCatalogue();
        $status = $candidate->status;

        return [
            'may_review' => $mayWrite,

            // Outright: READY only.
            'can_promote' => $mayWrite && $status->isPromotable(),

            // With an explicit override and a stated reason: NEEDS_REVIEW and
            // DUPLICATE. A person who has listened to the file may legitimately
            // overrule the machine; what they may not do is overrule it
            // silently.
            'can_promote_with_override' => $mayWrite && $status->isPromotableWithOverride(),
            'override_requires_note' => true,

            'can_reject' => $mayWrite
                && $status !== TrackCandidateStatus::Promoted
                && $status !== TrackCandidateStatus::Rejected,

            'can_revise' => $mayWrite && $status->isRevisable(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function preview(TrackCandidate $candidate, User $viewer): array
    {
        if ($candidate->asset === null) {
            return ['available' => false, 'reason' => null];
        }

        $decision = $this->policy->decide($candidate->asset, $viewer);

        return [
            'available' => $decision->isAllowed(),
            'reason' => $decision->isAllowed() ? null : $decision->value,
        ];
    }

    /**
     * What the analyser measured, or null when nothing has measured it.
     *
     * Null rather than an object full of nulls: "not measured" and "measured
     * as nothing" are different facts, and a host with no FFmpeg produces the
     * first, permanently and legitimately.
     *
     * @return array<string, mixed>|null
     */
    private function analysis(TrackCandidate $candidate): ?array
    {
        $analysis = AudioAnalysis::query()
            ->where('asset_id', $candidate->asset_id)
            ->orderByDesc('id')
            ->first();

        if (! $analysis instanceof AudioAnalysis) {
            return null;
        }

        return [
            'succeeded' => (bool) $analysis->succeeded,
            'analyzer' => $analysis->analyzer,
            'duration_ms' => $analysis->duration_ms,
            'codec' => $analysis->codec,
            'container' => $analysis->container,
            'sample_rate' => $analysis->sample_rate,
            'bit_depth' => $analysis->bit_depth,
            'channels' => $analysis->channels,
            'bitrate' => $analysis->bitrate,
            'loudness_lufs' => $analysis->loudness_lufs === null ? null : (float) $analysis->loudness_lufs,
            'peak_dbfs' => $analysis->peak_dbfs === null ? null : (float) $analysis->peak_dbfs,
            'analyzed_at' => $analysis->analyzed_at?->toAtomString(),

            // `failure_message` and `raw` are both withheld: the first quotes
            // the analyser, and on a failed read that quote contains a
            // filesystem path; the second is the analyser's whole output.
            // `succeeded` is what a reviewer acts on.
        ];
    }

    /**
     * What these bytes turned out to be, if the platform had them already.
     *
     * @return array<string, mixed>|null
     */
    private function duplicate(TrackCandidate $candidate): ?array
    {
        if ($candidate->matchedAsset === null) {
            return null;
        }

        return [
            'asset_uuid' => $candidate->matchedAsset->uuid,
            'track' => $candidate->matchedTrack === null ? null : [
                'uuid' => $candidate->matchedTrack->uuid,
                'title' => $candidate->matchedTrack->title,
            ],
        ];
    }

    /**
     * The operator's manifest row, and anything it contradicts.
     *
     * Returns null when no manifest was supplied — distinct from a manifest
     * that was supplied and said nothing, which a reviewer reading an empty
     * object would otherwise conclude.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>|null
     */
    private function manifest(array $metadata): ?array
    {
        $row = $metadata['manifest'] ?? null;

        if (! is_array($row) || $row === []) {
            return null;
        }

        /** @var list<array<string, mixed>> $conflicts */
        $conflicts = is_array($metadata['manifest_conflicts'] ?? null)
            ? array_values($metadata['manifest_conflicts'])
            : [];

        /** @var list<array<string, mixed>> $observations */
        $observations = is_array($metadata['manifest_observations'] ?? null)
            ? array_values($metadata['manifest_observations'])
            : [];

        return [
            // Named `claimed` rather than `metadata`. Every value under it is
            // something somebody asserted about the file, not something the
            // platform established, and the key is the last chance to say so
            // before a template renders it.
            'claimed' => [
                'title' => $this->text($row, 'title'),
                'artist' => $this->text($row, 'artist'),
                'release_title' => $this->text($row, 'release_title'),
                'disc_number' => $this->number($row, 'disc_number'),
                'track_number' => $this->number($row, 'track_number'),
                'language' => $this->text($row, 'language'),
                'isrc' => $this->text($row, 'isrc'),
                'upc' => $this->text($row, 'upc'),
                'legacy_provider' => $this->text($row, 'legacy_provider'),
                'notes' => $this->text($row, 'notes'),
            ],
            'line' => $this->number($row, 'line'),
            'extra' => is_array($row['extra'] ?? null) ? $row['extra'] : [],
            'conflicts' => $conflicts,
            'observations' => $observations,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function text(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function number(array $row, string $key): ?int
    {
        $value = $row[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }
}
