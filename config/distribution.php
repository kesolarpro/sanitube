<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default distributor
    |--------------------------------------------------------------------------
    |
    | `none` on a fresh install. No distributor account is wired to this
    | platform — Too Lost is the first intended adapter and arrives in DIST-002
    | — and the catalogue, the Studio and the release builder all work without
    | one. A distributor is an outbound channel, never the centre.
    |
    | `none`, never `null`: Laravel reads the literal string `null` in a .env
    | file as PHP null, which means "no value" rather than "the distributor
    | named null". Same rule as the AI and generation providers.
    |
    */

    'default' => env('SANITUBE_DISTRIBUTOR', 'none'),

    'distributors' => [
        'none' => ['driver' => 'none'],

        // Shipped, not test-only: it is what lets the whole delivery engine be
        // demonstrated and reviewed with no distributor account.
        'fake' => ['driver' => 'fake', 'sandbox' => true],
    ],

    /*
    |--------------------------------------------------------------------------
    | Manual delivery packages
    |--------------------------------------------------------------------------
    |
    | The delivery path that works everywhere. Almost no distributor publishes
    | an API, ADR-0018 forbids inventing one from a reverse-engineered wrapper,
    | and every one of them has a web portal — so a package is a metadata sheet
    | and a checked list of which stored objects belong to a release, and a
    | person uploads them.
    |
    | `columns` is the sheet, and it is data because every intake form differs:
    | the headings, their order and which are mandatory all vary between
    | distributors. **The heading on the left is whatever the form calls it; the
    | value on the right is a field SaniTube can answer** -- see ExportField,
    | which is a closed list. A heading naming something outside it is dropped
    | rather than rendered as a column of blanks, because a blank column reads
    | as data the catalogue does not hold.
    |
    | No distributor is named here or anywhere in the export code. An operator
    | with a different intake form edits this array; nobody patches a class.
    |
    | Where a package is written is deliberately *not* here. It is the prefix
    | the platform already owns for distribution output, which is the one the
    | importer already refuses to read back in -- a settable prefix would be a
    | way to put the platform's own output somewhere a later import would treat
    | as new material.
    |
    | The defaults are neutral, readable headings covering what an intake form
    | asks for at minimum. They are a starting point, not a specification.
    |
    */

    'export' => [

        'columns' => [
            'Disc' => 'disc_number',
            'Track' => 'track_number',
            'Track Title' => 'track_title',
            'Track Version' => 'track_version',
            'Track Artist' => 'track_artist',
            'ISRC' => 'isrc',
            'Language' => 'language_code',
            'Focus Track' => 'is_focus_track',
            'Release Title' => 'release_title',
            'Release Version' => 'release_version',
            'Release Artist' => 'release_artist',
            'Release Type' => 'release_type',
            'UPC' => 'upc',
            'Label' => 'label_name',
            'Catalogue Number' => 'catalogue_number',
            'Release Date' => 'release_date',
            'Original Release Date' => 'original_release_date',
            'P Line' => 'p_line',
            'C Line' => 'c_line',
            'File Name' => 'file_name',
            'File SHA-256' => 'file_checksum',
            'File Bytes' => 'file_bytes',
            'Duration (s)' => 'duration_seconds',
        ],

    ],

];
