<?php

declare(strict_types=1);

namespace SaniTube\Ingestion\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use SaniTube\Assets\Enums\AssetStatus;
use SaniTube\Assets\Models\Asset;
use SaniTube\Catalog\Enums\TrackSource;
use SaniTube\Catalog\Enums\TrackStatus;
use SaniTube\Catalog\Models\Track;
use SaniTube\Ingestion\Enums\IngestionSource;
use SaniTube\Ingestion\Enums\TrackCandidateStatus;
use SaniTube\Ingestion\Events\TrackCandidatePromoted;
use SaniTube\Ingestion\Exceptions\CandidateReviewException;
use SaniTube\Ingestion\Models\TrackCandidate;
use SaniTube\Localization\ContentLanguage;

/**
 * Turns a proposal into a recording in the catalogue.
 *
 * This is the single place in the platform where a Track comes into existence
 * from imported material, and it is a *human* decision by construction. Nine
 * hundred files were imported by a batch job at three in the morning; the
 * catalogue is the source of truth; the whole of ING-001 exists to keep those
 * two apart. This method is the door between them, and it is narrow on purpose.
 *
 * The track is created `DRAFT`. Promotion means "this belongs in the
 * catalogue", never "this is ready to release" — those are separate decisions
 * and collapsing them would let an import reach a distributor.
 */
final readonly class PromoteTrackCandidate
{
    /**
     * @param  array<string, mixed>  $attributes  reviewer-supplied catalogue data, which overrides every suggestion
     */
    public function handle(
        TrackCandidate $candidate,
        array $attributes = [],
        bool $override = false,
        ?string $note = null,
        ?int $reviewerId = null,
    ): Track {
        $this->guard($candidate, $override, $note);

        $title = $this->title($candidate, $attributes);
        $language = $this->language($attributes);
        $instrumental = $this->instrumental($attributes, $language);

        // I8 lives in TrackObserver and would throw on a mismatch. Deriving the
        // language here rather than letting it throw means a reviewer who ticks
        // "instrumental" gets the one correct language instead of a validation
        // error about ISO 639-2.
        if ($instrumental) {
            $language = ContentLanguage::INSTRUMENTAL;
        }

        if ($candidate->asset->status !== AssetStatus::Verified) {
            throw CandidateReviewException::assetNotVerified($candidate->uuid);
        }

        $master = $this->canonicalMaster($candidate);

        if (! $override) {
            $this->refuseIfMasterIsAlreadyInTheCatalogue($candidate);
        }

        return DB::transaction(function () use (
            $candidate, $attributes, $title, $language, $instrumental, $master, $note, $reviewerId
        ): Track {
            // The claim, and the reason promotion cannot happen twice under a
            // race. A guarded UPDATE is atomic on every engine in the matrix;
            // reading the status and then writing it is not.
            $claimed = TrackCandidate::query()
                ->whereKey($candidate->id)
                ->whereNull('promoted_track_id')
                ->where('status', '!=', TrackCandidateStatus::Promoted->value)
                ->update(['status' => TrackCandidateStatus::Processing->value]);

            if ($claimed === 0) {
                throw CandidateReviewException::alreadyPromoted($candidate->uuid);
            }

            $track = Track::query()->create([
                'title' => $title,
                'version_title' => $this->string($attributes['version_title'] ?? null),
                'master_asset_id' => $master->id,
                'language_code' => $language,
                'is_instrumental' => $instrumental,
                'is_explicit' => (bool) ($attributes['is_explicit'] ?? false),
                // Left null when the reviewer supplies none. Media fills it
                // from the analysis it measured, listening for the event below
                // — Ingestion asking Media directly would close a dependency
                // cycle, since Media already listens to Ingestion.
                'duration_ms' => $this->int($attributes['duration_ms'] ?? null),
                'bpm' => $this->int($attributes['bpm'] ?? null),
                'musical_key' => $this->string($attributes['musical_key'] ?? null),
                'genre_primary' => $this->string($attributes['genre_primary'] ?? null),
                'genre_secondary' => $this->string($attributes['genre_secondary'] ?? null),
                'recording_year' => $this->int($attributes['recording_year'] ?? null),
                'p_line' => $this->string($attributes['p_line'] ?? null),
                // DRAFT, always. Promotion is not publication.
                'status' => TrackStatus::Draft,
                'source' => $this->source($candidate, $attributes),
            ]);

            $candidate->forceFill([
                'status' => TrackCandidateStatus::Promoted,
                'promoted_track_id' => $track->id,
                'reviewed_at' => Carbon::now(),
                'reviewed_by' => $reviewerId,
                'review_note' => $note,
            ])->save();

            event(new TrackCandidatePromoted($candidate->refresh(), $track));

            return $track;
        });
    }

    private function guard(TrackCandidate $candidate, bool $override, ?string $note): void
    {
        if ($candidate->status === TrackCandidateStatus::Promoted) {
            throw CandidateReviewException::alreadyPromoted($candidate->uuid);
        }

        if ($candidate->status->isPromotable()) {
            return;
        }

        if (! $candidate->status->isPromotableWithOverride()) {
            // PENDING, WAITING_CAPABILITY, FAILED, REJECTED. None of these is
            // a verdict a reviewer can simply disagree with: the work has not
            // finished, or the decision was already taken.
            throw CandidateReviewException::notPromotable($candidate->status);
        }

        if (! $override) {
            throw CandidateReviewException::notPromotable($candidate->status);
        }

        if ($note === null || trim($note) === '') {
            throw CandidateReviewException::overrideNeedsANote($candidate->status);
        }
    }

    /**
     * The asset the catalogue should point at.
     *
     * When the same bytes were imported twice, AST-001 registers a *second*
     * asset carrying `duplicate_of_asset_id` rather than silently reusing the
     * first — the import happened, and an import that disappears is one nobody
     * can account for. The catalogue does not want that copy: one recording has
     * one master, and pointing a track at the duplicate would leave the
     * original unreferenced while both are paid for.
     */
    private function canonicalMaster(TrackCandidate $candidate): Asset
    {
        $duplicateOf = $candidate->asset->duplicate_of_asset_id;

        if ($duplicateOf === null) {
            return $candidate->asset;
        }

        return Asset::query()->find($duplicateOf) ?? $candidate->asset;
    }

    /**
     * One recording, one track.
     *
     * The check follows the duplicate chain, and that is the part that matters.
     * Checking only `master_asset_id` would let the same recording in twice: the
     * second import has a *different* asset id, because AST-001 records the
     * duplicate rather than discarding it. One recording under two identifiers
     * is what a distributor sees as two releases of the same track.
     *
     * Not a unique index on `tracks.master_asset_id`, and that is deliberate
     * restraint rather than oversight: `tracks` is ARCH-002's table, and
     * tightening a validated schema to enforce a rule introduced here would be
     * an amendment made in passing. The rule is enforced in the domain, it is
     * overridable with a stated reason, and the override exists because a
     * genuine second recording sharing a master is rare but not impossible.
     */
    private function refuseIfMasterIsAlreadyInTheCatalogue(TrackCandidate $candidate): void
    {
        $candidates = array_values(array_filter([
            $candidate->asset_id,
            $candidate->asset->duplicate_of_asset_id,
            $candidate->matched_asset_id,
        ]));

        $existing = Track::query()->whereIn('master_asset_id', $candidates)->first();

        if ($existing instanceof Track) {
            throw CandidateReviewException::masterAlreadyInCatalogue($existing->uuid);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function title(TrackCandidate $candidate, array $attributes): string
    {
        $supplied = $this->string($attributes['title'] ?? null);

        if ($supplied !== null) {
            return $supplied;
        }

        $suggested = $candidate->suggested_title
            ?? SuggestTitle::from($candidate->original_filename);

        return $suggested ?? throw CandidateReviewException::titleRequired();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function language(array $attributes): string
    {
        $supplied = $this->string($attributes['language_code'] ?? null);

        // `und` rather than a guess. Nothing about an imported file says what
        // language it is in, and a wrong language code reaches DSPs.
        return $supplied === null
            ? ContentLanguage::UNKNOWN
            : ContentLanguage::fromCode($supplied)->code;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function instrumental(array $attributes, string $language): bool
    {
        if (array_key_exists('is_instrumental', $attributes)) {
            return (bool) $attributes['is_instrumental'];
        }

        // A reviewer who set the language to "no linguistic content" has said
        // it is an instrumental, whether or not they ticked the box.
        return $language === ContentLanguage::INSTRUMENTAL;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function source(TrackCandidate $candidate, array $attributes): TrackSource
    {
        // LEGACY_IMPORT is load-bearing: it marks a recording that was already
        // distributed elsewhere and therefore already carries an ISRC the
        // platform must preserve rather than mint. Only a person knows this,
        // so only a person can say it.
        if ((bool) ($attributes['already_distributed'] ?? false)) {
            return TrackSource::LegacyImport;
        }

        return match ($candidate->source) {
            IngestionSource::ManualUpload => TrackSource::Upload,
            IngestionSource::Generated => TrackSource::Generated,
            default => TrackSource::CloudImport,
        };
    }

    private function string(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function int(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && ctype_digit($value) ? (int) $value : null;
    }
}
