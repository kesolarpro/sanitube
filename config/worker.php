<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Worker
    |--------------------------------------------------------------------------
    |
    | SaniTube Core runs where an operator can host PHP: cPanel, a small VPS,
    | anywhere. Some work cannot run there — a music model needs a GPU, and
    | shared hosting frequently has no FFmpeg at all — so a **worker** is the
    | same SaniTube application installed on a host that can, reached over one
    | authenticated boundary.
    |
    | One boundary, addressed by capability. Adding a kind of work later needs a
    | handler and a registration, not a second protocol.
    |
    | Empty `url` and `token` -- the shipped default -- means this installation
    | has no worker, which is the ordinary case. Core boots, every screen
    | renders, and everything that can run locally still does.
    |
    | On a worker host, `token` is what makes the routes exist at all: unset,
    | they answer 404 rather than falling back to something permissive. The same
    | value is set on Core as the credential to send.
    |
    */

    // Core: where the worker is. Worker: unused -- it does not call itself.
    'url' => env('SANITUBE_WORKER_URL', ''),

    /*
    | Both sides. A token of its own, never the catalogue API's: that one reads
    | a catalogue, this one spends GPU time, runs binaries and writes into
    | shared storage. It is never logged -- not the value, not a prefix, not a
    | fingerprint of it. The worker's `identity` below is what an audit line
    | carries instead.
    */
    'token' => env('SANITUBE_WORKER_TOKEN', ''),
    'token_header' => env('SANITUBE_WORKER_TOKEN_HEADER', 'X-SaniTube-Worker-Token'),

    /*
    | Worker: what to call this machine.
    |
    | Configured, never derived. A hostname changes when a machine is rebuilt
    | and an address changes when it moves; an audit line saying which worker
    | did something has to survive both. It appears in logs, so it is a label
    | somebody chose -- and never a token or a fingerprint of one.
    */
    'identity' => env('SANITUBE_WORKER_IDENTITY', ''),

    /*
    | Core's patience.
    |
    | Two numbers, because they answer different questions. The handshake is
    | asked on page loads and must fail fast on a worker that is switched off;
    | a job may legitimately take a quarter of an hour. One number would make
    | one of the two wrong.
    */
    'handshake_timeout_seconds' => (int) env('SANITUBE_WORKER_HANDSHAKE_TIMEOUT', 5),
    'timeout_seconds' => (int) env('SANITUBE_WORKER_TIMEOUT', 960),

    /*
    |--------------------------------------------------------------------------
    | Tool versions
    |--------------------------------------------------------------------------
    |
    | What the handshake reports about this worker's own binaries.
    |
    | "The worker has FFmpeg" is a weaker statement than an operator needs: a
    | five-year-old build and a current one both answer yes, and only one of
    | them decodes what a distributor will ask for. So the handshake carries
    | versions.
    |
    | **A list, in configuration, rather than names in the worker's code.** The
    | boundary is deliberately general -- it knows nothing about generation,
    | media processing or transcription -- and a hardcoded probe for `ffmpeg`
    | would be the first place that stopped being true. It also means an
    | operator can add a tool their own capability needs without touching PHP.
    |
    | The defaults read the same environment variables the media module does, so
    | a static build uploaded into a cPanel account is found once and reported
    | here too. Probed with `Process` and an argument array -- never a shell --
    | and cached, because a handshake is polled.
    |
    */

    'tools' => [
        'ffmpeg' => ['binary' => env('SANITUBE_FFMPEG_PATH', 'ffmpeg'), 'flag' => '-version'],
        'ffprobe' => ['binary' => env('SANITUBE_FFPROBE_PATH', 'ffprobe'), 'flag' => '-version'],
        'fpcalc' => ['binary' => env('SANITUBE_FPCALC_PATH', 'fpcalc'), 'flag' => '-version'],
    ],

];
