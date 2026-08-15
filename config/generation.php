<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default provider
    |--------------------------------------------------------------------------
    |
    | `none` on a fresh install. No music generation API is available to this
    | platform yet — Suno is the first intended adapter — and everything else
    | imports, analyses, catalogues and releases without one.
    |
    | `none`, never `null`: Laravel reads the literal string `null` in a .env
    | file as PHP null, which means "no value" rather than "the provider named
    | null". AI-001 learned that in CI; the same rule applies here so an
    | operator does not have to learn two conventions.
    |
    */

    'default' => env('SANITUBE_GENERATION_PROVIDER', 'none'),

    'providers' => [
        'none' => ['driver' => 'none'],

        // Shipped, not test-only. It is what makes the Studio demonstrable on
        // an installation with no provider account, and what a reviewer uses
        // to see the workflow end to end.
        'fake' => ['driver' => 'fake'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Polling
    |--------------------------------------------------------------------------
    |
    | Generation is asynchronous and the platform has no webhook endpoint yet,
    | so progress is discovered by polling. Both bounds below exist to stop a
    | job that re-dispatches itself from doing so for ever: a provider that
    | never answers must cost a fixed amount of work, not an unbounded one.
    |
    | `max_polls` is the hard stop — after it, the generation is failed with a
    | timeout rather than left QUEUED for ever, because a row that is
    | permanently in flight is a row nobody cleans up.
    |
    */

    'poll' => [
        'max_polls' => (int) env('SANITUBE_GENERATION_MAX_POLLS', 60),
        'interval_seconds' => (int) env('SANITUBE_GENERATION_POLL_INTERVAL', 30),
    ],

];
