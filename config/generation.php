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
    | Provider preference
    |--------------------------------------------------------------------------
    |
    | Which supplier to try first when a request does not name one. Providers
    | not listed still get used -- this states an order, not a whitelist, and
    | an installation that lost its only working provider by expressing a
    | preference would be a trap.
    |
    | An array here, or a comma-separated list in the environment, because a
    | value set in .env can only ever be a string and the same setting has to
    | mean the same thing either way.
    |
    | Selection is by capability first: a provider that cannot sing supplied
    | lyrics is not offered a request that supplies them, whatever the order
    | says. See SaniTube\MusicGeneration\Services\SelectGenerationProvider.
    |
    */

    'preference' => env('SANITUBE_GENERATION_PREFERENCE', []),

    /*
    |--------------------------------------------------------------------------
    | Withheld capabilities
    |--------------------------------------------------------------------------
    |
    | Each provider entry above may carry a `disabled_capabilities` list, which
    | switches off things the adapter believes its supplier can do:
    |
    |     'my-provider' => [
    |         'driver' => 'my-provider',
    |         'disabled_capabilities' => ['STEM_SEPARATION'],
    |     ],
    |
    | Removing only, never adding. A self-hosted deployment with no stem model
    | installed genuinely cannot separate stems however capable the software
    | is, and only the operator knows that. Granting a capability here would
    | instead produce a failure at the supplier that looks like a bug in
    | SaniTube, and would let a configuration file overrule the one place that
    | has actually read the provider's contract.
    |
    | Names come from SaniTube\MusicGeneration\Enums\GenerationCapability. An
    | unrecognised one is ignored rather than fatal: a typo must not stop
    | generation from booting.
    |
    */

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
    | Request ceilings
    |--------------------------------------------------------------------------
    |
    | How many generation requests this installation is willing to make in a
    | rolling 24 hours, 7 days and 30 days. Zero means no ceiling, and is the
    | shipped default: a platform whose first experience of the feature is a
    | refusal would be a worse platform.
    |
    | These count *requests*, not money. SaniTube holds no prices, no currency
    | and no balance, and this is not a step towards any of those — it is the
    | brake on a production plan that has been told to decide, unattended, that
    | more music should exist.
    |
    | Rolling rather than calendar: a calendar month needs a time zone to be
    | meaningful and a reset somebody has to remember, and both go wrong
    | quietly.
    |
    */

    'limits' => [
        'daily' => (int) env('SANITUBE_GENERATION_DAILY_LIMIT', 0),
        'weekly' => (int) env('SANITUBE_GENERATION_WEEKLY_LIMIT', 0),
        'monthly' => (int) env('SANITUBE_GENERATION_MONTHLY_LIMIT', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Circuit breaker
    |--------------------------------------------------------------------------
    |
    | A provider having an outage answers every request identically and
    | immediately, so a backlog discovers the outage once per item and burns
    | itself in the minutes before anybody notices. After this many consecutive
    | failed submissions the platform stops asking until the cooldown passes.
    |
    | Zero disables it, for an installation that would rather see every failure
    | than have requests withheld.
    |
    */

    'circuit' => [
        'consecutive_failures' => (int) env('SANITUBE_GENERATION_CIRCUIT_FAILURES', 5),
        'cooldown_minutes' => (int) env('SANITUBE_GENERATION_CIRCUIT_COOLDOWN', 15),
    ],

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
