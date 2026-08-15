<?php

declare(strict_types=1);

namespace SaniTube\Api\Http\Requests\V1;

use SaniTube\Ingestion\Enums\IngestionSource;
use SaniTube\Ingestion\Enums\TrackCandidateStatus;

final class TrackCandidateIndexRequest extends CatalogIndexRequest
{
    /**
     * @return array<string, list<string>|null>
     */
    protected function allowedFilters(): array
    {
        return [
            'status' => array_map(static fn (TrackCandidateStatus $s): string => $s->value, TrackCandidateStatus::cases()),
            'source' => array_map(static fn (IngestionSource $s): string => $s->value, IngestionSource::cases()),
        ];
    }
}
