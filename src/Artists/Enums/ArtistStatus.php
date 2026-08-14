<?php

declare(strict_types=1);

namespace SaniTube\Artists\Enums;

enum ArtistStatus: string
{
    case Active = 'ACTIVE';
    case Archived = 'ARCHIVED';
}
