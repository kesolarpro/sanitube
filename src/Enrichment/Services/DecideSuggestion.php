<?php

declare(strict_types=1);

namespace SaniTube\Enrichment\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use SaniTube\Audit\Enums\AuditAction;
use SaniTube\Audit\Services\RecordAuditEvent;
use SaniTube\Catalog\Exceptions\TrackMetadataException;
use SaniTube\Catalog\Models\Track;
use SaniTube\Catalog\Services\SetTrackMetadata;
use SaniTube\Enrichment\Enums\SuggestionStatus;
use SaniTube\Enrichment\Exceptions\SuggestionDecisionException;
use SaniTube\Enrichment\Models\MetadataSuggestion;

/**
 * A person answering what a model proposed.
 *
 * **This is where ADR-0016's boundary is actually enforced**, against a click
 * rather than in a docblock. Everything before it stored opinions; this is the
 * only path by which one of those opinions becomes catalogue data, and it does
 * so by calling the catalogue's own editor.
 *
 * Three things follow from that, and each is a mutation somebody could
 * otherwise make without noticing:
 *
 *   - **Nothing here writes a catalogue column.** {@see SetTrackMetadata} does,
 *     with the same validation and the same refusals a person editing a track
 *     directly would meet. A service that assigned `$track->title` would be a
 *     second, weaker editor with a model on the end of it.
 *   - **What is written is what the reviewer confirmed**, not what the model
 *     said. Edit-before-accept is the ordinary case rather than a special one:
 *     a model's title is right about half the time and nearly right rather
 *     often, and a queue that forced accept-or-reject would train people to
 *     accept nearly-right.
 *   - **The stored suggestion is not rewritten to match.** The row keeps what
 *     the model actually said, because "what did the model propose" and "what
 *     did the person accept" are different questions and a row that answered
 *     only the second would make a bad prompt version invisible.
 */
final readonly class DecideSuggestion
{
    public function __construct(
        private SetTrackMetadata $catalogue,
        private RecordAuditEvent $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $edited  the reviewer's version, which may differ from
     *                                        the model's in any field or in all of them
     *
     * @throws SuggestionDecisionException
     * @throws TrackMetadataException
     */
    public function accept(MetadataSuggestion $suggestion, User $reviewer, array $edited = []): Track
    {
        $this->refuseIfDecided($suggestion);

        $track = $this->trackFor($suggestion);

        if (! $track instanceof Track) {
            $this->audit->refused(AuditAction::SuggestionAccepted, 'SUGGESTION_NO_TRACK', $suggestion->uuid);

            throw SuggestionDecisionException::noTrack();
        }

        $attributes = $this->attributes($suggestion, $edited);

        if ($attributes === []) {
            $this->audit->refused(AuditAction::SuggestionAccepted, 'SUGGESTION_NOTHING_TO_ACCEPT', $suggestion->uuid);

            throw SuggestionDecisionException::nothingToAccept();
        }

        return DB::transaction(function () use ($suggestion, $reviewer, $track, $attributes): Track {
            // The claim, and the reason a resubmitted form cannot apply a
            // suggestion twice. A guarded UPDATE is atomic on every engine in
            // the matrix; reading the status and then writing it is not -- and
            // the second application would land on top of whatever a person
            // had edited since.
            $claimed = MetadataSuggestion::query()
                ->whereKey($suggestion->id)
                ->where('status', SuggestionStatus::Proposed->value)
                ->update([
                    'status' => SuggestionStatus::Accepted->value,
                    'decided_by' => $reviewer->id,
                    'decided_at' => Carbon::now(),
                ]);

            if ($claimed === 0) {
                throw SuggestionDecisionException::alreadyDecided(SuggestionStatus::Accepted);
            }

            // Through the catalogue's own service, inside the transaction, so
            // a refusal there leaves the suggestion undecided rather than
            // marked accepted with nothing written.
            $updated = $this->catalogue->handle($track, $attributes);

            $this->audit->record(
                AuditAction::SuggestionAccepted,
                subjectUuid: $suggestion->uuid,
                // Which fields, and whether the person changed the model's
                // answer. Not the values: an audit log is not a second copy of
                // the catalogue.
                context: [
                    // A string, not a list: the audit scrubber keeps only
                    // string keys matching a strict pattern, so a list's
                    // integer keys are silently dropped and the context lands
                    // as an empty array.
                    'fields' => implode(',', array_keys($attributes)),
                    'edited' => $this->wasEdited($suggestion, $attributes),
                    'track_uuid' => $updated->uuid,
                ],
            );

            return $updated;
        });
    }

    /**
     * @throws SuggestionDecisionException
     */
    public function reject(MetadataSuggestion $suggestion, User $reviewer): MetadataSuggestion
    {
        $this->refuseIfDecided($suggestion);

        $claimed = MetadataSuggestion::query()
            ->whereKey($suggestion->id)
            ->where('status', SuggestionStatus::Proposed->value)
            ->update([
                'status' => SuggestionStatus::Rejected->value,
                'decided_by' => $reviewer->id,
                'decided_at' => Carbon::now(),
            ]);

        if ($claimed === 0) {
            throw SuggestionDecisionException::alreadyDecided(SuggestionStatus::Rejected);
        }

        // The more interesting of the two lines. A rejection is a person saying
        // the platform was wrong, and a run of them against one prompt version
        // is how a bad prompt announces itself.
        $this->audit->record(AuditAction::SuggestionRejected, subjectUuid: $suggestion->uuid);

        return $suggestion->refresh();
    }

    /**
     * @throws SuggestionDecisionException
     */
    private function refuseIfDecided(MetadataSuggestion $suggestion): void
    {
        if ($suggestion->status !== SuggestionStatus::Proposed) {
            throw SuggestionDecisionException::alreadyDecided($suggestion->status);
        }
    }

    /**
     * The track this asset is the master of, if any.
     *
     * Master rather than any association, because that is what "this recording
     * is that track" means here. An asset used as a release cover is not the
     * subject of a transcript.
     */
    private function trackFor(MetadataSuggestion $suggestion): ?Track
    {
        return Track::query()->where('master_asset_id', $suggestion->asset_id)->first();
    }

    /**
     * What the reviewer confirmed, in the catalogue's own field names.
     *
     * The suggestion's vocabulary is not the catalogue's, and the translation
     * happens once, here. `suggested_genres` is a list because a model produces
     * a list; the catalogue holds a primary and a secondary because that is
     * what a distributor asks for, and flattening the rest away is a decision
     * rather than a loss — the full list stays on the suggestion row.
     *
     * @param  array<string, mixed>  $edited
     * @return array<string, mixed>
     */
    private function attributes(MetadataSuggestion $suggestion, array $edited): array
    {
        $genres = $this->genres($suggestion, $edited);

        $candidates = [
            'title' => array_key_exists('title', $edited) ? $edited['title'] : $suggestion->suggested_title,
            'language_code' => array_key_exists('language_code', $edited) ? $edited['language_code'] : $suggestion->suggested_language,
            'genre_primary' => $genres[0] ?? null,
            'genre_secondary' => $genres[1] ?? null,
        ];

        // A field the reviewer cleared and the model never offered is not an
        // instruction to blank the catalogue. Accepting a suggestion adds what
        // it proposed; removing something already in the catalogue is an edit
        // to the track, and a different act.
        return array_filter(
            $candidates,
            static fn (mixed $value): bool => is_string($value) && trim($value) !== '',
        );
    }

    /**
     * @param  array<string, mixed>  $edited
     * @return list<string>
     */
    private function genres(MetadataSuggestion $suggestion, array $edited): array
    {
        $source = array_key_exists('genres', $edited) && is_array($edited['genres'])
            ? $edited['genres']
            : ($suggestion->suggested_genres ?? []);

        $genres = [];

        foreach ($source as $genre) {
            if (is_string($genre) && trim($genre) !== '') {
                $genres[] = trim($genre);
            }
        }

        return $genres;
    }

    /**
     * Whether the person changed what the model said.
     *
     * Worth one boolean in the audit context: a prompt version whose answers
     * are always edited before acceptance is a prompt version that is not
     * working, and that is invisible from acceptance counts alone.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function wasEdited(MetadataSuggestion $suggestion, array $attributes): bool
    {
        $proposed = [
            'title' => $suggestion->suggested_title,
            'language_code' => $suggestion->suggested_language,
            'genre_primary' => ($suggestion->suggested_genres ?? [])[0] ?? null,
            'genre_secondary' => ($suggestion->suggested_genres ?? [])[1] ?? null,
        ];

        foreach ($attributes as $field => $value) {
            if (($proposed[$field] ?? null) !== $value) {
                return true;
            }
        }

        return false;
    }
}
