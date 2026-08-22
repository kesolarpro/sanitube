<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Supplier
    |--------------------------------------------------------------------------
    |
    | Speech-to-text, used to enrich a catalogue -- never to decide anything in
    | it. `none` is the default and a legitimate permanent choice: an
    | installation with no supplier ingests, deduplicates, organises and
    | distributes exactly as it would otherwise, and simply has no transcripts.
    |
    | An empty value, an unset variable and the literal `null` all mean `none`.
    | A name that is not configured is an error rather than a silent fallback,
    | because a misspelt supplier and a deliberate absence are different things
    | and only one of them should be quiet.
    |
    */

    'provider' => env('SANITUBE_TRANSCRIPTION_PROVIDER', 'none'),

    /*
    |--------------------------------------------------------------------------
    | Automatic transcription
    |--------------------------------------------------------------------------
    |
    | Off, and this default is the design rather than caution.
    |
    | Every other thing that follows a stored asset is free and local: a
    | checksum comparison, an acoustic fingerprint. A transcription is a paid
    | call to somebody else's server, per file. Turning this on over a library
    | of four thousand masters spends four thousand calls before anybody has
    | read the first transcript, and no ceiling elsewhere would stop it --
    | OPS-002 guards the queue's depth, not an external bill.
    |
    | So this is a decision an operator makes once they know what a transcript
    | is worth to them. Until they make it, the platform transcribes what
    | somebody asks for and nothing else. `sanitube:transcription:backlog`
    | exists for the deliberate, limited sweep.
    |
    */

    'automatic' => (bool) env('SANITUBE_TRANSCRIPTION_AUTOMATIC', false),

    /*
    |--------------------------------------------------------------------------
    | Configured suppliers
    |--------------------------------------------------------------------------
    |
    | This array is what an installation declares it intends to use, and the
    | manager refuses any name with nothing behind it. A name maps to a driver;
    | when no `driver` is given the name is the driver, so an installation may
    | declare `openai-eu` pointing at a different endpoint without inventing a
    | second adapter.
    |
    | No key, endpoint or credential is ever read outside this file, and none is
    | ever written to a transcript row or an exception message.
    |
    | Base URLs come from the environment with no default in PHP, exactly as the
    | AI providers and the object stores do: an installation behind a corporate
    | gateway, or one pointed at a self-hosted OpenAI-compatible endpoint,
    | changes an environment value and nothing else.
    |
    | The OpenAI credentials fall back to the ones the AI module already reads,
    | because they are the same account and pasting a key twice is how the two
    | copies drift apart. An installation that wants a separate key -- a
    | separate project, a separate budget -- sets the transcription-specific
    | variables and the fallback never applies.
    |
    */

    'providers' => [

        /*
         * Declared, though the manager answers this name before it reads this
         * array. CFG-006 put the choice on the settings screen, and a screen
         * offering only `openai` would make the shipped default the one option
         * an operator could not choose — while every other supplier family in
         * this platform declares its own `none`.
         */
        'none' => ['driver' => 'none'],

        'openai' => [
            'driver' => 'openai',
            'base_url' => env('SANITUBE_TRANSCRIPTION_OPENAI_BASE_URL', env('SANITUBE_OPENAI_BASE_URL')),
            'key' => env('SANITUBE_TRANSCRIPTION_OPENAI_KEY', env('SANITUBE_OPENAI_KEY')),

            /*
             * `whisper-1` is the model whose `verbose_json` segment structure
             * the official specification actually pins. The newer transcription
             * models are configurable here; defaulting to one on the assumption
             * that it returns the same segments would be a guess.
             */
            'model' => env('SANITUBE_TRANSCRIPTION_OPENAI_MODEL', 'whisper-1'),

            /*
             * A transcription is one long call. Longer than the AI timeout on
             * purpose: this one is bounded by how long the audio is, not by how
             * fast a model writes a paragraph.
             */
            'timeout' => (int) env('SANITUBE_TRANSCRIPTION_TIMEOUT', 300),

            /*
             * **Local policy, not a documented API limit.** The official
             * specification carries no maximum upload size, so this is a
             * decision about what this installation is willing to pay to
             * upload rather than a rule restated from elsewhere. 0 removes the
             * ceiling and leaves the supplier's own refusal as the authority.
             */
            'max_bytes' => (int) env('SANITUBE_TRANSCRIPTION_MAX_BYTES', 25 * 1024 * 1024),
        ],

    ],

];
