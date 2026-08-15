<?php

declare(strict_types=1);

namespace SaniTube\Api\Http\Requests\V1;

use SaniTube\Catalog\Enums\TrackSource;
use SaniTube\Catalog\Enums\TrackStatus;

final class TrackIndexRequest extends CatalogIndexRequest
{
    /**
     * @return array<string, list<string>|null>
     */
    protected function allowedFilters(): array
    {
        return [
            'status' => array_column(TrackStatus::cases(), 'value'),
            'source' => array_column(TrackSource::cases(), 'value'),
        ];
    }
}
