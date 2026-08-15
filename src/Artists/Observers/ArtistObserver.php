<?php

declare(strict_types=1);

namespace SaniTube\Artists\Observers;

use SaniTube\Artists\Events\ArtistCreated;
use SaniTube\Artists\Models\Artist;

final class ArtistObserver
{
    public function created(Artist $artist): void
    {
        event(new ArtistCreated($artist));
    }
}
