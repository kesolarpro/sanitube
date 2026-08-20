<?php

declare(strict_types=1);

namespace SaniTube\Enrichment;

use Illuminate\Support\ServiceProvider;
use SaniTube\Enrichment\Listeners\SuggestWhenTranscribed;
use SaniTube\Transcription\Events\AssetTranscribed;

/**
 * Wires enrichment to the one moment there is evidence to reason from.
 *
 * **One listener, off by default.** This is the second paid call on the same
 * asset — the first was the transcription that produced its evidence — so an
 * installation that has not decided a suggestion is worth paying for gets
 * suggestions only when somebody asks for one.
 */
final class EnrichmentServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app['events']->listen(AssetTranscribed::class, SuggestWhenTranscribed::class);
    }
}
