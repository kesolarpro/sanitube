<?php

declare(strict_types=1);

namespace SaniTube\Api\Http\Requests\V1;

use SaniTube\Releases\Enums\ReleaseStatus;
use SaniTube\Releases\Enums\ReleaseType;

final class ReleaseIndexRequest extends CatalogIndexRequest
{
    /**
     * @return array<string, list<string>|null>
     */
    protected function allowedFilters(): array
    {
        return [
            'status' => array_column(ReleaseStatus::cases(), 'value'),
            'type' => array_column(ReleaseType::cases(), 'value'),
        ];
    }
}
