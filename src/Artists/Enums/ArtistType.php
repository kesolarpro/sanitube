<?php

declare(strict_types=1);

namespace SaniTube\Artists\Enums;

/**
 * An Artist is a public credit, not a legal person. "Various Artists" is a
 * credit that appears on compilations and has no human behind it, which is
 * exactly why this is separate from Contributor.
 */
enum ArtistType: string
{
    case Person = 'PERSON';
    case Group = 'GROUP';
    case Project = 'PROJECT';
    case Various = 'VARIOUS';
}
