<?php

declare(strict_types=1);

namespace SaniTube\Api\Http\Middleware;

/**
 * Guards the endpoints that describe the environment.
 *
 * A readiness probe or a capability report tells an attacker which PHP
 * extensions, storage provider and database engine are in play, so these
 * routes carry their own token — separate from the catalogue token, because
 * the two have different blast radii and are handed to different systems.
 */
final class VerifyHealthToken extends VerifiesSharedToken
{
    protected function tokenConfigKey(): string
    {
        return 'sanitube.health.token';
    }

    protected function headerConfigKey(): string
    {
        return 'sanitube.health.header';
    }

    protected function defaultHeader(): string
    {
        return 'X-SaniTube-Health-Token';
    }

    protected function clientName(): string
    {
        return 'health';
    }
}
