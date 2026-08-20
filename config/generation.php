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

    /*
    |--------------------------------------------------------------------------
    | Submission claim
    |--------------------------------------------------------------------------
    |
    | How long one worker may hold the exclusive right to submit a generation
    | before another may take it. The claim is what stops two workers handed the
    | same job from both calling the provider and paying twice.
    |
    | It expires because its holder can die mid-call, and a claim that never
    | expired would leave that generation QUEUED for ever with nothing able to
    | pick it up. The number is a trade, not a fix: shorter risks asking a slow
    | provider twice, longer risks a crash stalling a generation. Fifteen
    | minutes is generous for an API call and short enough that a stall is
    | noticed rather than lived with.
    |
    */

    'submission_claim_seconds' => (int) env('SANITUBE_GENERATION_SUBMISSION_CLAIM_SECONDS', 900),

    'poll' => [
        'max_polls' => (int) env('SANITUBE_GENERATION_MAX_POLLS', 60),
        'interval_seconds' => (int) env('SANITUBE_GENERATION_POLL_INTERVAL', 30),
    ],

];
