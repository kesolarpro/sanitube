<?php

declare(strict_types=1);

namespace SaniTube\Deduplication;

use Illuminate\Support\ServiceProvider;
use SaniTube\Assets\Events\AssetStored;
use SaniTube\Deduplication\Console\EvaluateDuplicatesCommand;
use SaniTube\Deduplication\Listeners\EvaluateWhenFingerprinted;
use SaniTube\Deduplication\Listeners\EvaluateWhenStored;
use SaniTube\Media\Events\AssetFingerprinted;

/**
 * Wires deduplication to the two moments there is something new to compare.
 *
 * Both listeners, not one. Most installations have no Chromaprint, and if
 * evaluation only followed a fingerprint they would never find a duplicate at
 * all — not even the byte-identical ones, which are the commonest case and need
 * no acoustic comparison.
 */
final class DeduplicationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app['events']->listen(AssetStored::class, EvaluateWhenStored::class);
        $this->app['events']->listen(AssetFingerprinted::class, EvaluateWhenFingerprinted::class);

        if ($this->app->runningInConsole()) {
            $this->commands([EvaluateDuplicatesCommand::class]);
        }
    }
}
