<?php

declare(strict_types=1);

namespace SaniTube\Catalog\Enums;

enum CompositionStatus: string
{
    case Draft = 'DRAFT';
    case Registered = 'REGISTERED';
    case Archived = 'ARCHIVED';
}
