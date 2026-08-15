<?php

declare(strict_types=1);

namespace SaniTube\Api\Http\Requests\V1;

use SaniTube\Artists\Enums\ArtistStatus;
use SaniTube\Artists\Enums\ArtistType;

final class ArtistIndexRequest extends CatalogIndexRequest
{
    /**
     * @return array<string, list<string>|null>
     */
    protected function allowedFilters(): array
    {
        return [
            'status' => array_column(ArtistStatus::cases(), 'value'),
            'type' => array_column(ArtistType::cases(), 'value'),
        ];
    }
}
