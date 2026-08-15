<?php

declare(strict_types=1);

namespace SaniTube\Media\Listeners;

use SaniTube\Ingestion\Events\TrackCandidatePromoted;
use SaniTube\Media\Models\AudioAnalysis;

/**
 * Gives a newly promoted track the duration that was actually measured.
 *
 * A listener rather than a lookup inside promotion, and the direction is the
 * point: Media already listens to Ingestion, so Ingestion asking Media for an
 * analysis would close a dependency cycle between the two modules. The module
 * that holds the measurement is the module that applies it.
 *
 * Only when the reviewer supplied none. A typed duration overrides a measured
 * one, because a reviewer may be cataloguing an edit the master does not
 * reflect — and the person is the source of truth, not the probe.
 */
final readonly class RecordMeasuredDurationOnTrack
{
    public function handle(TrackCandidatePromoted $event): void
    {
        if ($event->track->duration_ms !== null) {
            return;
        }

        $measured = AudioAnalysis::query()
            ->where('asset_id', $event->candidate->asset_id)
            ->where('succeeded', true)
            ->whereNotNull('duration_ms')
            ->orderByDesc('id')
            ->value('duration_ms');

        if ($measured === null) {
            // No analyser on this host, or nothing measurable in the file.
            // A null duration is honest; a zero would be a number nothing
            // measured.
            return;
        }

        $event->track->forceFill(['duration_ms' => (int) $measured])->save();
    }
}
