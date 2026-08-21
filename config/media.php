<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Is analysis required before a candidate may be reviewed?
    |--------------------------------------------------------------------------
    |
    | Default false, and that default is the whole cPanel question.
    |
    | SaniTube has to run on shared hosting where FFmpeg is frequently absent
    | and the account cannot install it. If analysis were mandatory, every
    | candidate on such a host would sit in WAITING_CAPABILITY for ever and
    | nothing could ever enter the catalogue — the platform would be bricked by
    | a binary it was told to treat as optional.
    |
    | So absence of an analyser is not a verdict on a recording: the candidate
    | becomes READY and the analysis columns stay null, which is honest about
    | what was measured. An operator who *does* require the numbers — because a
    | distributor rejects masters below a loudness floor — sets this to true and
    | accepts that their host must provide FFmpeg.
    |
    | This never suppresses a finding. An analyser that runs and cannot make
    | sense of a file is a fact about the file and is reported either way.
    |
    */

    'analysis_required' => (bool) env('SANITUBE_MEDIA_ANALYSIS_REQUIRED', false),

    /*
    |--------------------------------------------------------------------------
    | ffprobe
    |--------------------------------------------------------------------------
    |
    | `path` is looked up on the PATH when it is a bare name. Give it an
    | absolute path when the binary was uploaded into the hosting account,
    | which is the usual arrangement on cPanel — there is no PATH directory
    | such a user may write to.
    |
    | The timeout bounds one file. It is generous because a cold shared host
    | reading a 500 MB WAV off a network mount is slow in a way that is not a
    | failure, and short enough that a hung probe cannot occupy a worker for
    | the rest of the night.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Acoustic fingerprinting
    |--------------------------------------------------------------------------
    |
    | Chromaprint's fpcalc, used to tell that two differently-encoded files are
    | the same recording. Absent on most shared hosting, and absent is fine:
    | the platform reports that it cannot compare acoustically rather than
    | failing. `length_seconds` bounds the decode -- the opening of a track
    | identifies it as well as the whole of it, and the whole of it is minutes
    | of CPU per file.
    |
    */

    'fpcalc' => [
        'path' => env('SANITUBE_FPCALC_PATH', 'fpcalc'),
        'timeout' => (int) env('SANITUBE_FPCALC_TIMEOUT', 120),
        'length_seconds' => (int) env('SANITUBE_FPCALC_LENGTH', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Execution strategy
    |--------------------------------------------------------------------------
    |
    | Where heavy media work runs: probing a file, taking a fingerprint.
    |
    |   local          run it on this machine, as every installation did before
    |                  WRK-002 -- and still the right answer for a VPS with the
    |                  tools installed: no network, no second host, no object
    |                  round trip.
    |
    |   remote_worker  send it to a worker, and if the worker cannot serve it,
    |                  report the capability unavailable. It does not quietly
    |                  fall back: an operator who selected a worker has usually
    |                  done so for a reason -- a shared host whose CPU limit
    |                  ffprobe trips -- that a silent fallback would defeat
    |                  without telling anybody.
    |
    |   auto           prefer a worker when one advertises the capability, use
    |                  this machine otherwise, and report unavailable when
    |                  neither can. The shipped default, because it is the only
    |                  one that is right on a bare cPanel account, on a full
    |                  VPS, and on an installation whose worker is temporarily
    |                  down.
    |
    | An unrecognised value is `auto` rather than a boot failure: a typo in a
    | .env on a shared host must not take an installation down.
    |
    | **Availability is never inferred from this setting.** A worker is used
    | only when its handshake succeeds and advertises the capability; the local
    | tool only when it is actually executable. See
    | SaniTube\Media\Execution\MediaExecution.
    |
    */

    'execution' => env('SANITUBE_MEDIA_EXECUTION', 'auto'),

    'ffprobe' => [
        'path' => env('SANITUBE_FFPROBE_PATH', 'ffprobe'),
        'timeout' => (int) env('SANITUBE_FFPROBE_TIMEOUT', 120),
    ],

];
