<?php

declare(strict_types=1);

/**
 * The suite must run against the repository, not a snapshot of it.
 *
 * `sanitube:deploy` caches the configuration and the route table — the right
 * thing on a server, and something `DeploymentTest` therefore does to this
 * checkout every time it runs. It cleans up after itself now, but a cache left
 * behind by an interrupted run, or by an operator trying the command by hand,
 * would still be read by the next `php artisan test`.
 *
 * What that looks like is the problem. A `config/` file added since the cache
 * was written reads as null and a route added since answers 404, so a feature
 * that works fails in whichever test happens to touch it, with an error that
 * describes the symptom and never the cause. It cost real time more than once
 * before it was tracked down.
 *
 * Removed rather than warned about: there is no situation in which running the
 * suite against a stale snapshot is what somebody wanted.
 */
foreach ((array) glob(__DIR__.'/../bootstrap/cache/{config.php,routes-*.php}', GLOB_BRACE) as $cached) {
    if (is_string($cached) && is_file($cached)) {
        @unlink($cached);
    }
}

require __DIR__.'/../vendor/autoload.php';
