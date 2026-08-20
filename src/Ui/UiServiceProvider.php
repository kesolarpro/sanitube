<?php

declare(strict_types=1);

namespace SaniTube\Ui;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use SaniTube\Foundation\Database\SchemaPresence;

final class UiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Navigation\NavigationTree::class);

        // `scoped`, not `singleton`: the cache must live exactly one request.
        // A migration between two page loads has to be seen, and a singleton
        // under a persistent worker would report a table as missing until the
        // process was restarted.
        $this->app->scoped(SchemaPresence::class);
    }

    public function boot(): void
    {
        /*
         * Minting a preview is the only interface action that produces a
         * credential, so it is the only one with its own budget. Sixty a minute
         * is far above any human browsing pattern and far below what a script
         * would need to collect a catalogue's worth of signed URLs.
         *
         * Keyed on the authenticated user rather than the address: an office
         * behind one IP must not share a quota, and one compromised session must
         * not be able to spend everybody else's. The address is the fallback
         * only for a request with no user, which `auth` should already have
         * refused — it exists so the limiter cannot be bypassed if that ever
         * stops being true.
         */
        RateLimiter::for('sanitube-asset-preview', fn (Request $request): Limit => $request->user() !== null
            ? Limit::perMinute(60)->by('preview:user:'.$request->user()->getAuthIdentifier())
            : Limit::perMinute(10)->by('preview:ip:'.$request->ip()));
    }
}
