<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
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
