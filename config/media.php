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

    'ffprobe' => [
        'path' => env('SANITUBE_FFPROBE_PATH', 'ffprobe'),
        'timeout' => (int) env('SANITUBE_FFPROBE_TIMEOUT', 120),
    ],

];
