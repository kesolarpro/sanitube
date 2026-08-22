<?php

declare(strict_types=1);

namespace Tests\Support\Ui;

use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

/**
 * Run a test with CSRF verification actually switched on.
 *
 * Laravel's own middleware short-circuits when it detects a test run, which is
 * the right default: nobody wants to mint a token in four hundred feature
 * tests that are about something else. The cost of that default is that a
 * whole class of defect is invisible to the suite — and UPL-005 is exactly
 * that class. Every relayed upload in production was refused with 419 because
 * the layout had stopped publishing the token, while the feature tests that
 * cover the same route stayed green, because for them the check does not run.
 *
 * So the check is switched back on deliberately, for the handful of tests
 * whose subject *is* the check. What this must never become is a way to switch
 * it off somewhere it applies: the fix for a 419 is to carry the token, and a
 * test that stopped verifying it would be asserting the defect.
 */
trait EnforcesCsrfTokens
{
    protected function enforceCsrfTokens(): void
    {
        $this->app->bind(
            ValidateCsrfToken::class,
            fn ($app): ValidateCsrfToken => new class($app, $app->make(Encrypter::class)) extends ValidateCsrfToken
            {
                /**
                 * The one line that differs from the framework's.
                 *
                 * Everything else — how the token is read from the request,
                 * which methods are exempt, how the cookie is refreshed — is
                 * the real implementation, because a re-implementation would
                 * be testing itself.
                 */
                protected function runningUnitTests(): bool
                {
                    return false;
                }
            },
        );
    }
}
