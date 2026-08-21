<?php

declare(strict_types=1);

namespace SaniTube\Storage\Certification;

use DateTimeImmutable;
use Illuminate\Http\Client\Factory as HttpFactory;
use SaniTube\Storage\Contracts\StorageProvider;
use SaniTube\Storage\Contracts\SupportsDirectUpload;
use Throwable;

/**
 * Proving a provider can do the things production actually asks of it.
 *
 * `sanitube:storage:check` answers "can this install store a master?" — write,
 * read back, delete. That is the right question to ask on a health screen and
 * the wrong one to certify a deployment on, because three of the operations
 * the platform depends on are not among them:
 *
 *   - **`move`.** A completed upload is promoted from its staging key to its
 *     canonical one server-side. Credentials with `PutObject` and no
 *     `CopyObject` pass a write/read/delete probe and lose every upload at the
 *     last step.
 *   - **A signed URL that a browser can actually fetch.** Today's report says
 *     `temporary_urls: yes`, which means the provider *claims* it can sign.
 *     Whether the signature is accepted depends on endpoint addressing, clock
 *     skew and credential scope — none of which the minting call touches. So
 *     this fetches the URL over plain HTTP, with no credentials, exactly as a
 *     browser would.
 *   - **A presigned `PUT` that accepts bytes.** The direct-upload path is the
 *     only way a two-gigabyte master gets in on a shared account, and it is
 *     signed by a different code path than the read.
 *
 * **What this still cannot prove is CORS**, because only a browser can: a
 * cross-origin `PUT` is refused by the browser before the request is made, and
 * nothing reaches the server to be observed. That is a runbook step, not a
 * check, and the report says so rather than implying coverage it does not have.
 *
 * Nothing here is destructive. Every object it writes lives under one prefix
 * and is deleted in a `finally`, including when a check fails.
 */
final readonly class CertifyStorage
{
    /**
     * Deliberately the same prefix the health probe writes to, so one lifecycle
     * rule on the bucket covers both and an operator has one path to recognise
     * rather than two. `StorageCertificationTest` asserts they have not drifted.
     */
    public const PREFIX = '.sanitube-health';

    /**
     * Long enough for a slow link, short enough that a URL in a terminal
     * scrollback tomorrow is worthless.
     */
    private const URL_TTL_SECONDS = 300;

    private const HTTP_TIMEOUT_SECONDS = 30;

    public function __construct(private HttpFactory $http) {}

    public function certify(StorageProvider $provider, ?DateTimeImmutable $now = null): CertificationReport
    {
        $nonce = bin2hex(random_bytes(8));
        $payload = 'sanitube-certification-'.$nonce;

        $staging = self::PREFIX.'/certify-'.$nonce.'.staging.txt';
        $canonical = self::PREFIX.'/certify-'.$nonce.'.canonical.txt';
        $direct = self::PREFIX.'/certify-'.$nonce.'.direct.txt';

        $checks = [];

        try {
            $checks[] = $this->attempt('write', function () use ($provider, $staging, $payload): void {
                $provider->put($staging, $payload);
            });

            // Read back rather than trusting the write. An endpoint that
            // accepts writes and serves stale or empty objects is a real
            // failure mode, invisible to a write-only probe.
            $checks[] = $this->attempt('read', function () use ($provider, $staging, $payload): void {
                $this->expect($provider->get($staging) === $payload, 'The object read back does not match what was written.');
            });

            // Streams the object out of storage and hashes what is there —
            // the same call `sanitube:assets:verify` depends on.
            $checks[] = $this->attempt('checksum', function () use ($provider, $staging, $payload): void {
                $this->expect(
                    $provider->checksum($staging) === hash('sha256', $payload),
                    'The provider computed a different digest than the bytes that were written.',
                );
            });

            // The promotion every completed upload goes through.
            $checks[] = $this->attempt('move', function () use ($provider, $staging, $canonical): void {
                $provider->move($staging, $canonical);

                $this->expect($provider->exists($canonical), 'The object is not at its destination after a move.');
                $this->expect(! $provider->exists($staging), 'The object is still at its origin after a move.');
            });

            $checks[] = $this->signedRead($provider, $canonical, $payload, $now);
            $checks[] = $this->presignedWrite($provider, $direct, $payload, $now);

            $checks[] = $this->attempt('delete', function () use ($provider, $canonical): void {
                $this->expect($provider->delete($canonical), 'The provider reported the delete as unsuccessful.');
                $this->expect(! $provider->exists($canonical), 'The object still exists after being deleted.');
            });
        } finally {
            // Including when a check threw. A certification run that leaves
            // litter in a bucket is one an operator learns not to run.
            foreach ([$staging, $canonical, $direct] as $key) {
                $this->forget($provider, $key);
            }
        }

        return new CertificationReport($provider->name(), $checks);
    }

    /**
     * Fetch a signed URL the way a browser would: plain HTTP, no credentials.
     *
     * This is the check that distinguishes "the provider can sign" from "the
     * service accepts the signature", and the two come apart for ordinary
     * reasons — path-style versus virtual-host addressing, a clock minutes off,
     * a credential scoped to a different bucket.
     */
    private function signedRead(
        StorageProvider $provider,
        string $key,
        string $payload,
        ?DateTimeImmutable $now,
    ): CertificationCheck {
        if (! $provider->supportsTemporaryUrls()) {
            return CertificationCheck::skipped(
                'signed_read',
                'This provider cannot sign URLs. Audio is streamed through the application instead.',
            );
        }

        return $this->attempt('signed_read', function () use ($provider, $key, $payload, $now): void {
            $url = $provider->temporaryUrl($key, $this->deadline($now));

            $response = $this->http
                ->timeout(self::HTTP_TIMEOUT_SECONDS)
                ->withoutRedirecting()
                ->get($url);

            // The status, never the URL. The signature is the permission.
            $this->expect(
                $response->successful(),
                sprintf('The service refused the signed URL with HTTP %d.', $response->status()),
            );

            $this->expect(
                $response->body() === $payload,
                'The signed URL was accepted but served different bytes.',
            );
        });
    }

    /**
     * Write through a presigned `PUT`, then read the object back through the
     * provider — which is what proves the bytes landed at the key we
     * authorised, and not merely that something accepted a request.
     */
    private function presignedWrite(
        StorageProvider $provider,
        string $key,
        string $payload,
        ?DateTimeImmutable $now,
    ): CertificationCheck {
        if (! $provider instanceof SupportsDirectUpload) {
            return CertificationCheck::skipped(
                'presigned_write',
                'This provider cannot accept a direct browser upload. Uploads go through the application.',
            );
        }

        return $this->attempt('presigned_write', function () use ($provider, $key, $payload, $now): void {
            $upload = $provider->temporaryUploadUrl($key, $this->deadline($now));

            $response = $this->http
                ->timeout(self::HTTP_TIMEOUT_SECONDS)
                ->withHeaders($upload->headers)
                ->withBody($payload)
                ->put($upload->url);

            $this->expect(
                $response->successful(),
                sprintf('The service refused the presigned upload with HTTP %d.', $response->status()),
            );

            $this->expect(
                $provider->get($key) === $payload,
                'The presigned upload was accepted but the object is not readable at the key it was signed for.',
            );
        });
    }

    private function deadline(?DateTimeImmutable $now): DateTimeImmutable
    {
        return ($now ?? new DateTimeImmutable)->modify('+'.self::URL_TTL_SECONDS.' seconds');
    }

    /**
     * @param  callable(): void  $check
     */
    private function attempt(string $name, callable $check): CertificationCheck
    {
        try {
            $check();
        } catch (Throwable $exception) {
            return CertificationCheck::failed($name, $exception->getMessage());
        }

        return CertificationCheck::passed($name);
    }

    /**
     * @throws CertificationFailed
     */
    private function expect(bool $condition, string $detail): void
    {
        if ($condition) {
            return;
        }

        throw new CertificationFailed($detail);
    }

    private function forget(StorageProvider $provider, string $key): void
    {
        try {
            $provider->delete($key);
        } catch (Throwable) {
            // Reported by the check that failed, not by the tidying up.
        }
    }
}
