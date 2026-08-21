<?php

declare(strict_types=1);

namespace SaniTube\Worker\Client;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as HttpFactory;
use SaniTube\Worker\Enums\WorkerCapability;
use SaniTube\Worker\WorkerIdentity;
use SaniTube\Worker\WorkerJobFailed;
use Throwable;

/**
 * Core's one way of talking to a worker.
 *
 * Every subsystem that needs work done elsewhere goes through here, so there is
 * one authentication scheme, one failure vocabulary and one timeout policy
 * rather than one of each per capability.
 *
 * **Identity is cached**, because it is asked on page loads — a doctor screen,
 * a studio screen deciding whether to offer a button — and a handshake per
 * render would put a network round trip in front of every one. Briefly cached:
 * a worker that has just come back should be usable within a minute, not an
 * hour.
 *
 * Nothing here throws for an unconfigured worker. `identity()` returns null,
 * which is what "this installation has no worker" looks like, and it is the
 * ordinary case.
 */
final readonly class WorkerClient
{
    private const IDENTITY_TTL_SECONDS = 60;

    /**
     * @param  array<string, mixed>  $settings  the `worker` config block
     */
    public function __construct(
        private HttpFactory $http,
        private Cache $cache,
        private array $settings = [],
    ) {}

    public function isConfigured(): bool
    {
        return $this->url() !== '' && $this->token() !== '';
    }

    /**
     * Who the worker is, or null when there is none or it cannot be reached.
     *
     * Null covers both, deliberately: a caller deciding whether to offer
     * generation has the same answer either way, and the two are told apart on
     * the doctor screen, where somebody is actually looking.
     */
    public function identity(bool $fresh = false): ?WorkerIdentity
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $key = 'sanitube.worker.identity.'.md5($this->url());

        if ($fresh) {
            $this->cache->forget($key);
        }

        /** @var array<string, mixed>|null $payload */
        $payload = $this->cache->remember($key, self::IDENTITY_TTL_SECONDS, function (): array {
            try {
                $response = $this->http
                    ->timeout($this->handshakeTimeout())
                    ->acceptJson()
                    ->withHeaders([$this->tokenHeader() => $this->token()])
                    ->get($this->url().'/api/worker');

                if (! $response->successful()) {
                    return [];
                }

                $body = $response->json();

                return is_array($body) ? $body : [];
            } catch (Throwable) {
                // Never throws. A screen asking whether a worker exists must
                // render whatever the answer is.
                return [];
            }
        });

        return is_array($payload) && $payload !== [] ? WorkerIdentity::fromArray($payload) : null;
    }

    public function can(WorkerCapability $capability): bool
    {
        return $this->identity()?->can($capability) ?? false;
    }

    /**
     * Ask the worker to do something, and return what it says.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws WorkerJobFailed
     */
    public function run(WorkerCapability $capability, array $payload): array
    {
        if (! $this->isConfigured()) {
            throw WorkerJobFailed::because('WORKER_NOT_CONFIGURED');
        }

        try {
            $response = $this->http
                ->timeout($this->jobTimeout())
                ->acceptJson()
                ->asJson()
                ->withHeaders([$this->tokenHeader() => $this->token()])
                ->post($this->url().'/api/worker/jobs/'.$capability->slug(), $payload);
        } catch (Throwable) {
            // The message is not carried: a client exception quotes the
            // request, and this one carries the worker's address and its token
            // header.
            throw WorkerJobFailed::because('WORKER_UNREACHABLE');
        }

        if ($response->successful()) {
            $body = $response->json();

            if (! is_array($body)) {
                throw WorkerJobFailed::because('WORKER_MALFORMED_RESPONSE');
            }

            return $body;
        }

        $code = $response->json('code');

        // The worker's own machine-readable code, passed through unchanged. It
        // comes from a closed set this repository defines, so it cannot carry a
        // credential and it is the only useful thing an operator can be told
        // about a failure on the far side.
        throw WorkerJobFailed::because(is_string($code) && $code !== '' ? $code : 'WORKER_REFUSED');
    }

    private function url(): string
    {
        $configured = $this->settings['url'] ?? null;

        return is_string($configured) ? rtrim(trim($configured), '/') : '';
    }

    private function token(): string
    {
        $configured = $this->settings['token'] ?? null;

        return is_string($configured) ? trim($configured) : '';
    }

    private function tokenHeader(): string
    {
        $configured = $this->settings['token_header'] ?? null;

        return is_string($configured) && trim($configured) !== ''
            ? trim($configured)
            : 'X-SaniTube-Worker-Token';
    }

    private function handshakeTimeout(): int
    {
        // Short, and separate from the job timeout. A handshake that inherited
        // a generation's patience would hang a screen for minutes on a worker
        // that is simply switched off.
        return max(1, (int) ($this->settings['handshake_timeout_seconds'] ?? 5));
    }

    private function jobTimeout(): int
    {
        return max(1, (int) ($this->settings['timeout_seconds'] ?? 960));
    }
}
