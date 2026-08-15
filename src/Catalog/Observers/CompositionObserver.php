<?php

declare(strict_types=1);

namespace SaniTube\Catalog\Observers;

use SaniTube\Catalog\Events\CompositionCreated;
use SaniTube\Catalog\Models\Composition;

final class CompositionObserver
{
    public function created(Composition $composition): void
    {
        event(new CompositionCreated($composition));
    }
}
