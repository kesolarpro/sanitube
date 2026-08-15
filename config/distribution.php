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

];
