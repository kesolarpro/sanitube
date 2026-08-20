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
    case DuplicateRelation = 'duplicate_relation';

    /**
     * Filed under the identifier, not the track or release it names.
     *
     * The identifier row is the thing with a history: it is assigned once,
     * revoked at most once, and never edited. Filing under the entity would
     * scatter that history through everything else that happened to a track,
     * and would lose which of two ISRCs the entry was about once a replacement
     * exists — which is exactly the moment somebody goes looking.
     */
    case ExternalIdentifier = 'external_identifier';

    /**
     * Filed under the suggestion rather than the asset or the track.
     *
     * The question an audit answers here is "what did somebody decide about
     * this proposal", and filing it under the track would mix a decision about
     * a model's opinion in with edits a person made directly.
     */
    case MetadataSuggestion = 'metadata_suggestion';
    case IngestionBatch = 'ingestion_batch';
    case Generation = 'generation';
    case FailedJob = 'failed_job';
    case System = 'system';
}
