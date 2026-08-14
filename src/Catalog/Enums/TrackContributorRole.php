<?php

declare(strict_types=1);

namespace SaniTube\Catalog\Enums;

enum TrackContributorRole: string
{
    case Performer = 'PERFORMER';
    case Producer = 'PRODUCER';
    case Engineer = 'ENGINEER';
    case Mixer = 'MIXER';
    case Mastering = 'MASTERING';
    case Programmer = 'PROGRAMMER';
}
