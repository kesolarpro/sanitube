<?php

declare(strict_types=1);

namespace SaniTube\Api\Http\Requests\V1;

use SaniTube\Ingestion\Enums\IngestionBatchStatus;
use SaniTube\Ingestion\Enums\IngestionSource;

final class IngestionBatchIndexRequest extends CatalogIndexRequest
{
    /**
     * @return array<string, list<string>|null>
     */
    protected function allowedFilters(): array
    {
        return [
            'status' => array_map(static fn (IngestionBatchStatus $s): string => $s->value, IngestionBatchStatus::cases()),
            'source' => array_map(static fn (IngestionSource $s): string => $s->value, IngestionSource::cases()),
        ];
    }
}
