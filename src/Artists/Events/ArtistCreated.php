<?php

declare(strict_types=1);

namespace SaniTube\Artists\Events;

use Illuminate\Foundation\Events\Dispatchable;
use SaniTube\Artists\Models\Artist;

/**
 * A new public credit exists in the catalogue.
 */
final class ArtistCreated
{
    use Dispatchable;

    public function __construct(public readonly Artist $artist) {}
}
