<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | UI locales
    |--------------------------------------------------------------------------
    |
    | These are *interface* locales only. They are deliberately unrelated to
    | the language of a track, a release or a set of lyrics — see
    | SaniTube\Localization\ContentLanguage for that.
    |
    | Adding a locale is additive: append an entry here, add the matching
    | `lang/<code>` directory, and nothing else in the application changes.
    | `direction` is honoured by the UI, so right-to-left languages (ar, he,
    | fa, ur) drop in without a redesign.
    |
    */

    'supported' => [
        'en' => ['name' => 'English', 'native' => 'English', 'direction' => 'ltr'],
        'fr' => ['name' => 'French', 'native' => 'Français', 'direction' => 'ltr'],
        'es' => ['name' => 'Spanish', 'native' => 'Español', 'direction' => 'ltr'],
        'it' => ['name' => 'Italian', 'native' => 'Italiano', 'direction' => 'ltr'],
        'pt' => ['name' => 'Portuguese', 'native' => 'Português', 'direction' => 'ltr'],
        'de' => ['name' => 'German', 'native' => 'Deutsch', 'direction' => 'ltr'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Defaults
    |--------------------------------------------------------------------------
    |
    | Both values must appear in `supported`, otherwise the registry falls back
    | to the first supported locale rather than serving an untranslated UI.
    |
    */

    'default' => env('APP_LOCALE', 'en'),

    'fallback' => env('APP_FALLBACK_LOCALE', 'en'),

    /*
    |--------------------------------------------------------------------------
    | Negotiation
    |--------------------------------------------------------------------------
    |
    | Order of precedence when resolving the locale of an incoming request:
    | explicit query parameter, stored preference (session), then the browser's
    | Accept-Language header. Any step can be disabled independently.
    |
    */

    'negotiation' => [
        'query_parameter' => env('SANITUBE_LOCALE_QUERY', 'lang'),
        'session_key' => 'sanitube.locale',
        'use_accept_language' => (bool) env('SANITUBE_LOCALE_FROM_HEADER', true),
    ],

];
