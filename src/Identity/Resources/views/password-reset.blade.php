{{--
    The sign-in page.

    Blade rather than a Vue island, deliberately: this is the one screen that
    must render before any application JavaScript has been trusted, and it
    needs no client state at all. UI-001's Vue shell begins after this.

    Every string goes through the translator — SaniTube ships six locales and a
    hardcoded "Sign in" would be the first one to rot.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ __('identity.reset.title') }} — {{ config('app.name') }}</title>
    <style>
        /* Inline and minimal. This page must render correctly before the
           asset pipeline has been built, which is precisely the state a fresh
           installation is in. */
        :root { color-scheme: light dark; }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100dvh; display: grid; place-items: center;
            font: 15px/1.5 ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
            background: #f6f6f7; color: #18181b; padding: 1.5rem;
        }
        @media (prefers-color-scheme: dark) { body { background: #0b0b0d; color: #f4f4f5; } }
        .card {
            width: 100%; max-width: 25rem; padding: 2rem;
            border: 1px solid rgb(0 0 0 / .08); border-radius: .75rem; background: #fff;
        }
        @media (prefers-color-scheme: dark) {
            .card { background: #141417; border-color: rgb(255 255 255 / .1); }
        }
        h1 { margin: 0 0 .25rem; font-size: 1.25rem; letter-spacing: -.01em; }
        p.sub { margin: 0 0 1.5rem; opacity: .65; font-size: .875rem; }
        label { display: block; margin-bottom: .375rem; font-weight: 500; font-size: .875rem; }
        input[type=email], input[type=password] {
            width: 100%; padding: .625rem .75rem; margin-bottom: 1rem; font: inherit;
            border: 1px solid rgb(0 0 0 / .18); border-radius: .5rem; background: transparent; color: inherit;
        }
        @media (prefers-color-scheme: dark) {
            input[type=email], input[type=password] { border-color: rgb(255 255 255 / .18); }
        }
        input:focus-visible, button:focus-visible {
            outline: 2px solid #6366f1; outline-offset: 2px;
        }
        .remember { display: flex; align-items: center; gap: .5rem; margin-bottom: 1.25rem; font-size: .875rem; }
        .remember label { margin: 0; font-weight: 400; }
        button {
            width: 100%; padding: .625rem 1rem; font: inherit; font-weight: 500; cursor: pointer;
            border: 0; border-radius: .5rem; background: #18181b; color: #fff;
        }
        @media (prefers-color-scheme: dark) { button { background: #f4f4f5; color: #18181b; } }
        .errors {
            margin: 0 0 1rem; padding: .75rem; border-radius: .5rem; font-size: .875rem;
            background: rgb(220 38 38 / .1); color: #b91c1c; border: 1px solid rgb(220 38 38 / .2);
        }
        @media (prefers-color-scheme: dark) { .errors { color: #fca5a5; } }
        .errors ul { margin: 0; padding-left: 1.1rem; }
    </style>
</head>
<body>
    <main class="card">
        <h1>{{ __('identity.reset.title') }}</h1>
        <p class="sub">{{ config('app.name') }}</p>

        @if ($errors->any())
            {{-- role=alert so a screen reader announces the failure rather
                 than leaving the user to discover the form did nothing. --}}
            <div class="errors" role="alert">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('password.update') }}">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">

            <label for="email">{{ __('identity.auth.email') }}</label>
            <input id="email" name="email" type="email" value="{{ old('email', $email) }}"
                   required autocomplete="username">

            <label for="password">{{ __('identity.reset.new_password') }}</label>
            <input id="password" name="password" type="password"
                   required autofocus autocomplete="new-password">

            <label for="password_confirmation">{{ __('identity.reset.confirm_password') }}</label>
            <input id="password_confirmation" name="password_confirmation" type="password"
                   required autocomplete="new-password">

            <button type="submit">{{ __('identity.reset.submit') }}</button>
        </form>
    </main>
</body>
</html>
