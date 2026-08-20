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
    | Configured suppliers
    |--------------------------------------------------------------------------
    |
    | Empty until an adapter ships. A supplier registers itself with the manager
    | from its own service provider; this array is what an installation declares
    | it intends to use, and the manager refuses any name with nothing behind
    | it.
    |
    | No key, endpoint or credential is ever read outside this file, and none is
    | ever written to a transcript row or an exception message.
    |
    */

    'providers' => [],

];
