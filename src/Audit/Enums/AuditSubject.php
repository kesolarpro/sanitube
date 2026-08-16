<?php

declare(strict_types=1);

namespace SaniTube\Audit\Enums;

/**
 * What an audited action was about.
 *
 * A short domain word, never a class name. `SaniTube\Catalog\Models\Track` in
 * a log column is a namespace refactor away from being wrong about history,
 * and it tells a person reading the log about the code rather than about the
 * catalogue.
 *
 * `System` covers the actions with no row behind them — a backup, a prune —
 * where the subject is the installation itself. It is a real answer rather
 * than a missing one, and that distinction is why the column is not simply
 * nullable.
 */
enum AuditSubject: string
{
    case User = 'user';
    case Track = 'track';
    case TrackCandidate = 'track_candidate';
    case Release = 'release';
    case Delivery = 'delivery';
    case Asset = 'asset';
    case IngestionBatch = 'ingestion_batch';
    case Generation = 'generation';
    case FailedJob = 'failed_job';
    case System = 'system';
}
