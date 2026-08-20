<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Acoustic comparison
    |--------------------------------------------------------------------------
    |
    | How two fingerprints are lined up before their agreement is measured.
    | `max_offset_frames` is how far one may slide against the other, in
    | Chromaprint frames of roughly an eighth of a second: the same recording
    | encoded twice routinely starts a fraction of a second apart, and compared
    | head-to-head those two fingerprints score the same as two unrelated
    | files. `min_overlap_frames` is the least audio an answer may be based on,
    | because a comparison over a handful of frames is a coin toss that reports
    | itself as a percentage.
    |
    */

    'fingerprint' => [
        'max_offset_frames' => (int) env('SANITUBE_DEDUP_MAX_OFFSET_FRAMES', 20),
        'min_overlap_frames' => (int) env('SANITUBE_DEDUP_MIN_OVERLAP_FRAMES', 80),
    ],

    /*
    |--------------------------------------------------------------------------
    | Thresholds
    |--------------------------------------------------------------------------
    |
    | Where a measured agreement stops being a number and becomes a proposal.
    | These are policy, not measurement, which is why they live here and not in
    | the comparator: changing one re-reads stored fingerprints rather than
    | stored masters, so a recalibration is minutes rather than a re-ingest.
    |
    | Two unrelated recordings agree on about half their bits, because half is
    | what independent bits do. The defaults sit far above that.
    |
    */

    'thresholds' => [
        'same_recording' => (float) env('SANITUBE_DEDUP_SAME_RECORDING', 0.90),
        'possible_relation' => (float) env('SANITUBE_DEDUP_POSSIBLE_RELATION', 0.75),
    ],

    /*
    |--------------------------------------------------------------------------
    | Evaluation
    |--------------------------------------------------------------------------
    |
    | `enabled` turns acoustic evaluation off without uninstalling anything;
    | exact checksum matches are recorded regardless, because byte identity is
    | measured rather than inferred and costs nothing to observe.
    |
    */

    'enabled' => (bool) env('SANITUBE_DEDUP_ENABLED', true),

];
