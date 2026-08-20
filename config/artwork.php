<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Requirements
    |--------------------------------------------------------------------------
    |
    | What release artwork must be for this installation to consider it
    | deliverable.
    |
    | **These are settings, not facts, and no distributor is named anywhere in
    | this file or in the code that reads it.** Every store publishes its own
    | specification and they disagree at the edges; the defaults below are the
    | intersection that is commonly required, chosen so that artwork passing
    | them is unlikely to be rejected on shape alone. An operator delivering to
    | a store with different requirements changes these numbers rather than
    | patching a validator.
    |
    | Null or zero means "no requirement", and is honoured everywhere. A rule
    | nobody wants should be switched off rather than worked around.
    |
    */

    'requirements' => [

        // The shorter side, not the width. A 4000x1000 image satisfies "at
        // least 3000 wide" and is not usable as a cover.
        'minimum_pixels' => (int) env('SANITUBE_ARTWORK_MINIMUM_PIXELS', 3000),

        // An upper bound exists because some stores reject very large files
        // outright, and because a 20000px cover is nearly always a mistake
        // rather than an intention. Zero disables it.
        'maximum_pixels' => (int) env('SANITUBE_ARTWORK_MAXIMUM_PIXELS', 0),

        'must_be_square' => (bool) env('SANITUBE_ARTWORK_REQUIRE_SQUARE', true),

        // Bytes. Zero disables the check.
        'maximum_bytes' => (int) env('SANITUBE_ARTWORK_MAXIMUM_BYTES', 0),

        /*
        | Accepted media types, as measured from the file rather than from its
        | extension. A .jpg holding a PNG is a real thing and a store reads the
        | bytes, so this platform does too.
        |
        | An empty list means any type is accepted.
        */
        'accepted_mime_types' => [
            'image/jpeg',
            'image/png',
        ],

        /*
        | Whether CMYK artwork is refused.
        |
        | Only formats that record a channel count can be judged — JPEG does,
        | PNG does not. When nothing was measured the answer is "unknown", and
        | an unknown is never reported as a pass. See ValidateArtwork.
        */
        'refuse_cmyk' => (bool) env('SANITUBE_ARTWORK_REFUSE_CMYK', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Image generation
    |--------------------------------------------------------------------------
    |
    | `none` on a fresh install, and `none` rather than `null`: Laravel reads
    | the literal string `null` in a .env file as PHP null, which means "no
    | value" rather than "the provider named null". The same convention the
    | generation and transcription modules use, so an operator learns it once.
    |
    | **Read the note on `sizes` below before configuring this.** There is a
    | mismatch between what image models produce today and the `minimum_pixels`
    | above, and the platform refuses rather than spending into it.
    |
    */

    'default_provider' => env('SANITUBE_ARTWORK_PROVIDER', 'none'),

    'providers' => [

        'none' => ['driver' => 'none'],

        'openai' => [
            'driver' => 'openai',

            // Falls back to the shared OpenAI credentials, so an installation
            // that has configured transcription or enrichment does not
            // configure the same key a third time.
            'key' => env('SANITUBE_ARTWORK_OPENAI_KEY', env('SANITUBE_OPENAI_KEY')),
            // No default, deliberately, and the portability guardrail enforces
            // it: nothing in this platform hardcodes a domain. An installation
            // that has set no base URL has no image provider, which is the
            // correct state for one that has not configured one.
            'base_url' => env('SANITUBE_ARTWORK_OPENAI_BASE_URL', env('SANITUBE_OPENAI_BASE_URL')),
            'model' => env('SANITUBE_ARTWORK_OPENAI_MODEL', 'gpt-image-1'),
            'timeout' => (int) env('SANITUBE_ARTWORK_TIMEOUT', 120),

            // png, jpeg or webp per the published specification. Note that
            // `webp` is not in `accepted_mime_types` above by default, so
            // choosing it without also accepting it produces covers this
            // platform then rejects.
            'output_format' => env('SANITUBE_ARTWORK_OPENAI_FORMAT', 'png'),

            /*
            | What this installation may ask this provider for.
            |
            | **Declared, never inferred.** The platform does not work out from
            | a model name what an account may request. The published
            | specification lists different allowed sizes per model, describes
            | arbitrary sizes for one family under divisibility and aspect
            | constraints, and marks the largest as experimental — deriving a
            | usable set from that is exactly the guessing that is forbidden.
            |
            | The default is the one square size the specification lists as
            | supported across the GPT image models. **It is smaller than
            | `minimum_pixels` above**, which means generation refuses out of
            | the box with REQUIREMENTS_UNREACHABLE rather than producing covers
            | this platform's own validator rejects. That is deliberate: the
            | two numbers genuinely disagree today, and an operator resolves it
            | by lowering the requirement or by declaring a larger size their
            | account and model actually support.
            */
            'sizes' => ['1024x1024'],

            'output_mime_types' => ['image/png', 'image/jpeg', 'image/webp'],
        ],
    ],

];
