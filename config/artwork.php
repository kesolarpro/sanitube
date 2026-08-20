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

];
