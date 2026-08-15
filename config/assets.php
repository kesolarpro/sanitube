<?php

declare(strict_types=1);

use SaniTube\Assets\Enums\AssetKind;

return [

    /*
    |--------------------------------------------------------------------------
    | Upload size limits
    |--------------------------------------------------------------------------
    |
    | Per kind, in bytes. These bound what SaniTube will accept, and they are
    | enforced while the bytes are still streaming — an upload that exceeds its
    | limit is cut off and discarded rather than stored and then judged.
    |
    | They are not the only limit that applies. PHP's own upload_max_filesize
    | and post_max_size still cap a browser upload, and on shared hosting they
    | are usually the lower of the two. Raising a value here does not raise
    | those; see docs/storage.md.
    |
    | A master is allowed to be large on purpose. A 24-bit/96 kHz WAV of a long
    | track passes a gigabyte without anything being wrong.
    |
    */

    'max_upload_bytes' => [
        AssetKind::AudioMaster->value => (int) env('SANITUBE_MAX_MASTER_BYTES', 2 * 1024 * 1024 * 1024),
        AssetKind::AudioDerivative->value => (int) env('SANITUBE_MAX_DERIVATIVE_BYTES', 512 * 1024 * 1024),
        AssetKind::Preview->value => (int) env('SANITUBE_MAX_PREVIEW_BYTES', 64 * 1024 * 1024),
        AssetKind::Stem->value => (int) env('SANITUBE_MAX_STEM_BYTES', 2 * 1024 * 1024 * 1024),
        AssetKind::Artwork->value => (int) env('SANITUBE_MAX_ARTWORK_BYTES', 64 * 1024 * 1024),
        AssetKind::Video->value => (int) env('SANITUBE_MAX_VIDEO_BYTES', 8 * 1024 * 1024 * 1024),
        AssetKind::Lyrics->value => (int) env('SANITUBE_MAX_DOCUMENT_BYTES', 8 * 1024 * 1024),
        AssetKind::Document->value => (int) env('SANITUBE_MAX_DOCUMENT_BYTES', 8 * 1024 * 1024),
        AssetKind::LicenseDocument->value => (int) env('SANITUBE_MAX_DOCUMENT_BYTES', 8 * 1024 * 1024),
        AssetKind::DistributionExport->value => (int) env('SANITUBE_MAX_EXPORT_BYTES', 8 * 1024 * 1024 * 1024),
    ],

    /*
    |--------------------------------------------------------------------------
    | Staging
    |--------------------------------------------------------------------------
    |
    | Uploads land under a reserved prefix until they are proven, so a failed
    | upload never occupies the address the catalogue calls canonical.
    |
    | Objects left there past this age were abandoned — the client vanished,
    | the process died, the checksum never arrived — and the cleanup command
    | removes them. The window is generous because a slow upload of a large
    | master on a poor connection is not an abandoned one.
    |
    */

    /*

     * Preview URLs.

     *

     * A signed URL is a temporary bearer credential, so the window it is useful

     * for is the window an accidental disclosure is useful for. Fifteen minutes

     * is long enough to start playback on a slow connection and short enough

     * that a URL pasted into a chat is dead before anybody reads it. The value

     * is clamped to 300-900 seconds in code: a misconfiguration here must not

     * be able to quietly mint day-long credentials.

     */

    'preview' => [

        'ttl_seconds' => (int) env('SANITUBE_ASSET_PREVIEW_TTL', 900),

    ],

    'staging' => [
        'ttl_hours' => (int) env('SANITUBE_STAGING_TTL_HOURS', 24),
    ],

    /*
    |--------------------------------------------------------------------------
    | Duplicate handling
    |--------------------------------------------------------------------------
    |
    | The same bytes legitimately arrive twice: a re-upload after a browser
    | crash, the same master placed on two releases. That is recorded, never
    | rejected — an import that returns an error because a file is already
    | known loses the fact that it happened.
    |
    | `physical_deduplication` is off and is not implemented. Pointing two
    | assets at one object would turn deletion into a reference-counting
    | problem spanning releases and distributions, and get it wrong once is to
    | lose a master. See docs/adr/ADR-0012.
    |
    */

    'duplicates' => [
        'detect' => (bool) env('SANITUBE_DETECT_DUPLICATES', true),
        'physical_deduplication' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Verification
    |--------------------------------------------------------------------------
    |
    | How many assets a single verification pass will check by default. Bounded
    | so the command finishes inside a shared host's cron window rather than
    | being killed halfway through.
    |
    */

    'verification' => [
        'batch_size' => (int) env('SANITUBE_VERIFY_BATCH', 100),
    ],

];
