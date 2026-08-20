<?php

declare(strict_types=1);

namespace SaniTube\Catalog\Services;

use SaniTube\Audit\Enums\AuditAction;
use SaniTube\Audit\Services\RecordAuditEvent;
use SaniTube\Catalog\Enums\TrackStatus;
use SaniTube\Catalog\Exceptions\TrackMetadataException;
use SaniTube\Catalog\Models\Track;

/**
 * The one way editorial metadata reaches a track.
 *
 * **Written for ENR-004, and deliberately not part of it.** A suggestion is
 * accepted by a person, and what happens next has to be the same thing that
 * happens when a person edits a track directly — the same validation, the same
 * refusals, the same audit line. A path that wrote catalogue columns from an
 * enrichment row would be a second, weaker editor with an AI on the end of it,
 * which is precisely what ADR-0016 exists to prevent.
 *
 * So this class knows nothing about suggestions, models or providers. It takes
 * values from a caller and applies the catalogue's own rules to them.
 *
 * **A released track is not editable here.** Its metadata is what a distributor
 * was given, and changing it in place would leave the platform describing a
 * release differently from the shops carrying it. Correcting a released track
 * is a distribution act with its own consequences, not a metadata edit.
 */
final readonly class SetTrackMetadata
{
    /**
     * Fields this service is willing to write.
     *
     * A closed list, and short. Everything on it is editorial — something a
     * person decides — and nothing on it is measured. `duration_ms` and `bpm`
     * are absent because Media measured them from the file, and a person
     * typing over a measurement is a bug rather than a feature.
     */
    public const EDITABLE = ['title', 'language_code', 'genre_primary', 'genre_secondary'];

    public function __construct(private RecordAuditEvent $audit) {}

    /**
     * @param  array<string, mixed>  $attributes  only keys in {@see EDITABLE} are read
     *
     * @throws TrackMetadataException
     */
    public function handle(Track $track, array $attributes): Track
    {
        if ($track->status === TrackStatus::Released) {
            $this->audit->refused(AuditAction::TrackUpdated, 'TRACK_RELEASED', $track->uuid);

            throw TrackMetadataException::released();
        }

        $changes = [];

        foreach (self::EDITABLE as $field) {
            if (! array_key_exists($field, $attributes)) {
                continue;
            }

            $changes[$field] = $this->{$field}($attributes[$field]);
        }

        if ($changes === []) {
            // Not an error and not a write. A caller that sent nothing this
            // service is willing to write gets the track back unchanged and no
            // audit line, because nothing happened.
            return $track;
        }

        $track->forceFill($changes)->save();

        $this->audit->record(
            AuditAction::TrackUpdated,
            subjectUuid: $track->uuid,
            // The field names, never the values. An audit log is a record of
            // what was touched, and filling it with catalogue text would make
            // it a second copy of the catalogue.
            //
            // Joined into a string rather than passed as a list, because the
            // audit scrubber accepts only string keys matching a strict
            // pattern -- a list's integer keys are dropped, and the context
            // arrives as an empty array. That is deliberate on its side and
            // silent on this one, so the join is here rather than a surprise
            // in the log.
            context: ['fields' => implode(',', array_keys($changes))],
        );

        return $track->refresh();
    }

    /**
     * @throws TrackMetadataException
     */
    private function title(mixed $value): string
    {
        $title = is_string($value) ? trim($value) : '';

        if ($title === '') {
            throw TrackMetadataException::titleRequired();
        }

        if (mb_strlen($title) > 255) {
            throw TrackMetadataException::titleTooLong();
        }

        return $title;
    }

    /**
     * @throws TrackMetadataException
     */
    private function language_code(mixed $value): string
    {
        $code = is_string($value) ? strtolower(trim($value)) : '';

        // A code, and only a code. `language_code` is read by the distribution
        // export, where a word instead of a key is a rejected delivery.
        if (preg_match('/^[a-z]{2,3}$/', $code) !== 1) {
            throw TrackMetadataException::languageNotACode();
        }

        return $code;
    }

    private function genre_primary(mixed $value): ?string
    {
        return $this->genre($value);
    }

    private function genre_secondary(mixed $value): ?string
    {
        return $this->genre($value);
    }

    private function genre(mixed $value): ?string
    {
        $genre = is_string($value) ? trim($value) : '';

        // Nullable, because clearing a genre is a legitimate edit and an empty
        // string in a nullable column is a value that reads as "set to
        // nothing" in some queries and "unset" in others.
        return $genre === '' ? null : mb_substr($genre, 0, 128);
    }
}
