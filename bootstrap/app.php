<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use SaniTube\Localization\Http\Middleware\SetLocale;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Every HTML request is rendered in a negotiated locale from the very
        // first commit, so no screen can be built assuming English.
        $middleware->web(append: [
            SetLocale::class,
        ]);

        $middleware->throttleApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A failed validation redirects back with the input flashed into the
        // session, and Laravel's default exclusion list knows about `password`
        // and `password_confirmation` — which is every secret the framework
        // can guess the name of, and not every secret this platform has.
        //
        // `db_password` is typed into the web installer and `token` is both
        // the installer's filesystem credential and a password-reset token.
        // Flashed, they sit in the session waiting for somebody to add an
        // entirely reasonable `value="{{ old('db_password') }}"` — at which
        // point a secret is rendered into HTML by a template that did nothing
        // wrong. Found by a mutation that survived, which is what a surviving
        // mutation is for.
        $exceptions->dontFlash([
            'current_password',
            'password',
            'password_confirmation',
            'db_password',
            'token',
        ]);
    })->create();
