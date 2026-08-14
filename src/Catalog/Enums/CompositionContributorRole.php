<?php

declare(strict_types=1);

namespace SaniTube\Catalog\Enums;

/**
 * How a Contributor participates in a Composition.
 *
 * The `share` recorded alongside this role is the credit as captured at
 * catalogue time. It is deliberately not the Rights model: RGT-001 and PUB-001
 * introduce territory, term and administration, and must be free to disagree
 * with what was typed in here.
 */
enum CompositionContributorRole: string
{
    case Composer = 'COMPOSER';
    case Lyricist = 'LYRICIST';
    case Arranger = 'ARRANGER';
    case Adapter = 'ADAPTER';
    case Translator = 'TRANSLATOR';
    case Publisher = 'PUBLISHER';
    case Administrator = 'ADMINISTRATOR';
}
