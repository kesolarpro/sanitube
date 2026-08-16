{{--
    The web installer.

    Blade, inline CSS, no JavaScript at all. This page runs before the asset
    pipeline has been built, before the database has been reached and before
    anybody has signed in — every dependency it takes is one more way for it to
    be the screen that cannot tell you why nothing works.

    Nothing typed into this form is ever rendered back. Not on success, not on a
    validation failure: the database password and the owner's password would
    otherwise travel through the session into the HTML of the next response,
    which is exactly what an installer must not do.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('installer.title') }} — {{ config('app.name') }}</title>
    <style>
        :root { color-scheme: light dark; }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100dvh; display: grid; place-items: start center;
            font: 15px/1.5 ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
            background: #f6f6f7; color: #18181b; padding: 2rem 1.5rem;
        }
        @media (prefers-color-scheme: dark) { body { background: #0b0b0d; color: #f4f4f5; } }
        .card {
            width: 100%; max-width: 34rem; padding: 2rem;
            border: 1px solid rgb(0 0 0 / .08); border-radius: .75rem; background: #fff;
        }
        @media (prefers-color-scheme: dark) {
            .card { background: #141417; border-color: rgb(255 255 255 / .1); }
        }
        h1 { margin: 0 0 .25rem; font-size: 1.25rem; letter-spacing: -.01em; }
        h2 { margin: 1.75rem 0 .75rem; font-size: .9375rem; letter-spacing: -.005em; }
        p.sub { margin: 0 0 1.5rem; opacity: .65; font-size: .875rem; }
        label { display: block; margin-bottom: .375rem; font-weight: 500; font-size: .875rem; }
        input, select {
            width: 100%; padding: .625rem .75rem; margin-bottom: 1rem; font: inherit;
            border: 1px solid rgb(0 0 0 / .18); border-radius: .5rem; background: transparent; color: inherit;
        }
        @media (prefers-color-scheme: dark) { input, select { border-color: rgb(255 255 255 / .18); } }
        input:focus-visible, select:focus-visible, button:focus-visible { outline: 2px solid #6366f1; outline-offset: 2px; }
        button {
            width: 100%; padding: .625rem 1rem; font: inherit; font-weight: 500; cursor: pointer;
            border: 0; border-radius: .5rem; background: #18181b; color: #fff;
        }
        @media (prefers-color-scheme: dark) { button { background: #f4f4f5; color: #18181b; } }
        .notice, .errors {
            margin: 0 0 1.25rem; padding: .75rem; border-radius: .5rem; font-size: .875rem;
            border: 1px solid rgb(0 0 0 / .12);
        }
        .errors { background: rgb(220 38 38 / .1); color: #b91c1c; border-color: rgb(220 38 38 / .2); }
        @media (prefers-color-scheme: dark) { .errors { color: #fca5a5; } }
        .errors ul { margin: 0; padding-left: 1.1rem; }
        code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .8125rem; }
        .stages { list-style: none; margin: 0 0 1.25rem; padding: 0; font-size: .875rem; }
        .stages li { padding: .375rem 0; border-bottom: 1px solid rgb(0 0 0 / .06); }
        .stages li:last-child { border-bottom: 0; }
        .outcome { font-weight: 600; margin-right: .5rem; }
        .FAILED { color: #b91c1c; }
        .SKIPPED { opacity: .7; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0 .75rem; }
    </style>
</head>
<body>
    <main class="card">
        <h1>{{ __('installer.title') }}</h1>
        <p class="sub">{{ __('installer.description') }}</p>

        @if ($errors->any())
            <div class="errors" role="alert">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($results !== [])
            {{-- Where the last attempt stopped. A stage that reported SKIPPED
                 is shown too: "the key already existed so I left it alone" is
                 exactly what somebody re-running this needs to see. --}}
            <h2>{{ __('installer.last_attempt') }}</h2>
            <ul class="stages">
                @foreach ($results as $result)
                    <li>
                        <span class="outcome {{ $result['outcome'] }}">{{ $result['outcome'] }}</span>
                        <strong>{{ $result['stage'] }}</strong> — {{ $result['detail'] }}
                    </li>
                @endforeach
            </ul>
        @endif

        <div class="notice">
            {!! __('installer.token_help', ['path' => '<code>'.e($tokenLocation).'</code>']) !!}
        </div>

        <form method="POST" action="{{ route('install.store') }}">
            @csrf

            <label for="token">{{ __('installer.token') }}</label>
            {{-- Never repopulated from `old()`. It is a credential, and this
                 page's whole job is not putting credentials into HTML. --}}
            <input id="token" name="token" type="text" required autofocus autocomplete="off" spellcheck="false">

            <h2>{{ __('installer.application') }}</h2>

            <label for="app_url">{{ __('installer.app_url') }}</label>
            <input id="app_url" name="app_url" type="url" required value="{{ url('/') }}">

            <h2>{{ __('installer.database') }}</h2>

            <label for="db_connection">{{ __('installer.db_connection') }}</label>
            <select id="db_connection" name="db_connection" required>
                {{-- MariaDB is its own entry rather than a flavour of MySQL:
                     the two differ in how they store JSON and in what an index
                     over a foreign key does, and picking the wrong one is a
                     problem discovered months later. --}}
                <option value="mysql">MySQL</option>
                <option value="mariadb">MariaDB</option>
                <option value="sqlite">SQLite</option>
            </select>

            <div class="grid">
                <div>
                    <label for="db_host">{{ __('installer.db_host') }}</label>
                    <input id="db_host" name="db_host" type="text" value="127.0.0.1">
                </div>
                <div>
                    <label for="db_port">{{ __('installer.db_port') }}</label>
                    <input id="db_port" name="db_port" type="text" value="3306">
                </div>
            </div>

            <label for="db_database">{{ __('installer.db_database') }}</label>
            <input id="db_database" name="db_database" type="text" required>

            <label for="db_username">{{ __('installer.db_username') }}</label>
            <input id="db_username" name="db_username" type="text" autocomplete="off">

            <label for="db_password">{{ __('installer.db_password') }}</label>
            <input id="db_password" name="db_password" type="password" autocomplete="new-password">

            <h2>{{ __('installer.owner') }}</h2>
            <p class="sub">{{ __('installer.owner_description') }}</p>

            <label for="name">{{ __('installer.name') }}</label>
            <input id="name" name="name" type="text" required>

            <label for="email">{{ __('installer.email') }}</label>
            <input id="email" name="email" type="email" required autocomplete="username">

            <label for="password">{{ __('installer.password') }}</label>
            <input id="password" name="password" type="password" required autocomplete="new-password">

            <label for="password_confirmation">{{ __('installer.confirm_password') }}</label>
            <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password">

            <button type="submit">{{ __('installer.submit') }}</button>
        </form>
    </main>
</body>
</html>
