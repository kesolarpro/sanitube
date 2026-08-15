<?php

declare(strict_types=1);

namespace SaniTube\Catalog\Enums;

enum TrackArtistRole: string
{
    case Primary = 'PRIMARY';
    case Featured = 'FEATURED';
    case Remixer = 'REMIXER';
    case With = 'WITH';
}
