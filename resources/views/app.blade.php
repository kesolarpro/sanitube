<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    {{--
        UPL-005. The token the interface signs its own requests with.

        Inertia's own visits carry it for free: axios reads the `XSRF-TOKEN`
        cookie and sets `X-XSRF-TOKEN`. The screens that upload do not use
        Inertia — a `router.post` cannot report progress and cannot be
        aborted — so they build an `XMLHttpRequest` or a `fetch` by hand and
        read the token from here.

        Without this line those requests sent an empty `X-CSRF-TOKEN`, the
        middleware refused them with 419, and not one byte ever reached a
        controller. Nothing was logged, because a rendered 419 is not a
        reported exception. This is the whole of the contract between the
        layout and the JavaScript that reads it, and `CsrfTokenIsAvailableTest`
        is what keeps it.
    --}}
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title inertia>{{ config('app.name') }}</title>

    {{--
        Theme applied before first paint.

        This has to be a blocking inline script. Doing it in the Vue app means
        the browser paints the light theme first and then swaps — the flash of
        white that makes a dark interface feel broken. Four lines here is the
        price of not having it.
    --}}
    <script>
        (function () {
            try {
                var stored = localStorage.getItem('sanitube.theme');
                var dark = stored === 'dark'
                    || ((!stored || stored === 'system') && window.matchMedia('(prefers-color-scheme: dark)').matches);
                document.documentElement.classList.toggle('dark', dark);
            } catch (e) {
                // Storage can be unavailable — private mode, a locked-down
                // browser. The default theme is correct, so there is nothing
                // to recover from.
            }
        })();
    </script>

    @vite(['resources/css/app.css', 'resources/js/app.ts'])
    @inertiaHead
</head>
<body class="min-h-full antialiased">
    @inertia
</body>
</html>
