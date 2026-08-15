<?php

declare(strict_types=1);

namespace SaniTube\Api\Http\Middleware;

/**
 * Guards the read-only catalogue API.
 *
 * Deliberately a different token from the health one: a monitoring system
 * needs readiness and has no business reading the catalogue, and an internal
 * consumer of the catalogue has no business enumerating the server's
 * capabilities.
 */
final class VerifyInternalApiToken extends VerifiesSharedToken
{
    protected function tokenConfigKey(): string
    {
        return 'sanitube.api.internal_token';
    }

    protected function headerConfigKey(): string
    {
        return 'sanitube.api.token_header';
    }

    protected function defaultHeader(): string
    {
        return 'X-SaniTube-Api-Token';
    }
}
