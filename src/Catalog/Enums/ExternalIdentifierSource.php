<?php

declare(strict_types=1);

namespace SaniTube\Catalog\Enums;

enum ExternalIdentifierSource: string
{
    case Manual = 'MANUAL';
    case Import = 'IMPORT';
    case Distributor = 'DISTRIBUTOR';
    case Generated = 'GENERATED';
}
