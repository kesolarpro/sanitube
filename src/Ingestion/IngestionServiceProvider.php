<?php

declare(strict_types=1);

namespace SaniTube\Ingestion;

use Illuminate\Support\ServiceProvider;
use SaniTube\Ingestion\Models\IngestionItem;
use SaniTube\Ingestion\Observers\IngestionItemObserver;

final class IngestionServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // `active_marker` is derived from status on every write, so the unique
        // index that carries the idempotency guarantee cannot drift out of
        // step with the state it is supposed to describe.
        IngestionItem::observe(IngestionItemObserver::class);
    }
}
