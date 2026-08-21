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
    | Generation worker
    |--------------------------------------------------------------------------
    |
    | Where the GPU is.
    |
    | ACE-Step's verified contract answers with `output_path` -- a path on the
    | engine's own filesystem. SaniTube Core runs on cPanel, on a small VPS, on
    | whatever an operator has; the engine runs where a GPU is. Making Core open
    | that path would mean making the two share a filesystem, and that is a
    | deployment SaniTube must never require.
    |
    | So a worker sits between them. It is the same SaniTube application,
    | installed on the GPU host with a token set: it calls the local engine,
    | opens the file, and stages the bytes into the storage both sides share.
    | Core is handed a storage key, which it already knows how to ingest from
    | anywhere.
    |
    | `url` and `token` empty -- the shipped default -- means this installation
    | has no worker, which is the ordinary case. Core boots, the studio renders,
    | and every provider that is not self-hosted carries on.
    |
    | On the worker host, `token` is what makes the endpoint exist at all: unset,
    | it answers 404 rather than falling back to something permissive. The same
    | value is set on Core as the credential to send.
    |
    */

    'worker' => [
        // The transport -- address, token, timeouts -- lives in config/worker.php
        // now. WRK-001 made the boundary general, and a second copy of the
        // worker's address here would be a second place for it to be wrong.
        //
        // What stays is what belongs to *generation*.

        // Worker: what it will accept from its own engine before staging.
        // Zero means no ceiling. A limit is checked before the file is read,
        // never while streaming it -- a limit checked during a transfer is one
        // that has already cost the transfer.
        'max_output_bytes' => (int) env('SANITUBE_GENERATION_WORKER_MAX_BYTES', 0),

        // Worker: extensions it will stage. An engine that wrote something
        // else has not produced audio, whatever it reported.
        'allowed_extensions' => ['wav', 'flac', 'mp3'],

        // Worker: where staged objects land, in the shared storage.
        'staging_prefix' => env('SANITUBE_GENERATION_WORKER_STAGING_PREFIX', 'staging/generation'),

        // Core: a label for provenance, never sent to the worker -- the worker
        // chooses its own checkpoint. What an operator calls what they run.
        'model_label' => env('SANITUBE_GENERATION_WORKER_MODEL_LABEL', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | ACE-Step engine (worker host only)
    |--------------------------------------------------------------------------
    |
    | Read only by a generation worker. Core never has these set and never needs
    | them.
    |
    | Written against `infer-api.py` in ACE-Step's own repository. Three things
    | that file makes necessary:
    |
    |   - it loads the model *inside* the request handler, so `timeout_seconds`
    |     is generous and is configuration rather than a number in the code;
    |   - `checkpoint_path` and `device_id` come from the client, so they are
    |     set here by the operator and no part of a generation request can reach
    |     them;
    |   - `output_path` is client-chosen, so the worker always supplies one --
    |     and checks what comes back against `output_root` anyway, resolved
    |     before compared, because an engine that ignores the path it was given
    |     is exactly the case worth catching.
    |
    | `endpoint` has no default. Inventing `http://localhost:8000` would make a
    | misconfigured worker look like a working one until it silently talked to
    | whatever else was on that port.
    |
    */

    'acestep' => [
        'endpoint' => env('SANITUBE_ACESTEP_ENDPOINT', ''),
        'checkpoint_path' => env('SANITUBE_ACESTEP_CHECKPOINT_PATH', ''),
        'device_id' => (int) env('SANITUBE_ACESTEP_DEVICE_ID', 0),
        'bf16' => (bool) env('SANITUBE_ACESTEP_BF16', true),
        'torch_compile' => (bool) env('SANITUBE_ACESTEP_TORCH_COMPILE', false),

        // The only directory this worker will open a file from. Absolute, on
        // the worker host, and it must exist -- an unresolvable root refuses
        // every generation rather than widening.
        'output_root' => env('SANITUBE_ACESTEP_OUTPUT_ROOT', ''),

        'timeout_seconds' => (int) env('SANITUBE_ACESTEP_TIMEOUT', 900),
        'health_timeout_seconds' => (int) env('SANITUBE_ACESTEP_HEALTH_TIMEOUT', 5),

        // Rendering parameters, all of them the operator's. None is reachable
        // from a generation request: one that could set `infer_step` would be
        // one that decides what a generation costs to run.
        'defaults' => [
            'audio_duration' => (float) env('SANITUBE_ACESTEP_DURATION', 60),
            'infer_step' => (int) env('SANITUBE_ACESTEP_INFER_STEP', 60),
            'guidance_scale' => (float) env('SANITUBE_ACESTEP_GUIDANCE', 15),
            'scheduler_type' => env('SANITUBE_ACESTEP_SCHEDULER', 'euler'),
            'cfg_type' => env('SANITUBE_ACESTEP_CFG_TYPE', 'apg'),
            'omega_scale' => (float) env('SANITUBE_ACESTEP_OMEGA', 10),
            'guidance_interval' => (float) env('SANITUBE_ACESTEP_GUIDANCE_INTERVAL', 0.5),
            'guidance_interval_decay' => (float) env('SANITUBE_ACESTEP_GUIDANCE_DECAY', 0),
            'min_guidance_scale' => (float) env('SANITUBE_ACESTEP_MIN_GUIDANCE', 3),
            'use_erg_tag' => (bool) env('SANITUBE_ACESTEP_ERG_TAG', true),
            'use_erg_lyric' => (bool) env('SANITUBE_ACESTEP_ERG_LYRIC', true),
            'use_erg_diffusion' => (bool) env('SANITUBE_ACESTEP_ERG_DIFFUSION', true),
            // Empty means the engine chooses. A fixed seed here would make
            // every generation on an installation identical.
            'seeds' => [],
        ],
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
