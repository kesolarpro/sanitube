<?php

declare(strict_types=1);

namespace SaniTube\Contributors\Enums;

/**
 * A Contributor is the party that actually created or administers something —
 * a writer, a producer, a publisher. Rights and publishing attach here, never
 * to an Artist credit.
 */
enum ContributorType: string
{
    case Person = 'PERSON';
    case Organisation = 'ORGANISATION';
}
