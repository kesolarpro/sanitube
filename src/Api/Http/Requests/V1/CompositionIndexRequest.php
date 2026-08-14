<?php

declare(strict_types=1);

namespace SaniTube\Api\Http\Requests\V1;

use SaniTube\Catalog\Enums\CompositionStatus;

final class CompositionIndexRequest extends CatalogIndexRequest
{
    /**
     * @return array<string, list<string>|null>
     */
    protected function allowedFilters(): array
    {
        return [
            'status' => array_column(CompositionStatus::cases(), 'value'),
        ];
    }
}
