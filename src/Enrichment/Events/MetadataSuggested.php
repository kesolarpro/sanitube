<?php

declare(strict_types=1);

namespace SaniTube\Enrichment\Events;

use Illuminate\Foundation\Events\Dispatchable;
use SaniTube\Enrichment\Models\MetadataSuggestion;

/**
 * A model has proposed something and it is waiting for a person.
 *
 * The seam a review screen, a notification or a digest hangs off, so Enrichment
 * stays ignorant of who is waiting to be told. It carries the row rather than
 * the fields: a listener that quoted a payload would have no way to say which
 * stored suggestion it was talking about.
 */
final class MetadataSuggested
{
    use Dispatchable;

    public function __construct(public readonly MetadataSuggestion $suggestion) {}
}
