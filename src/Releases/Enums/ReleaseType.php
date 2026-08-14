<?php

declare(strict_types=1);

namespace SaniTube\Releases\Enums;

enum ReleaseType: string
{
    case Single = 'SINGLE';
    case Ep = 'EP';
    case Album = 'ALBUM';
    case Compilation = 'COMPILATION';
}
