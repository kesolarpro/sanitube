<?php

declare(strict_types=1);

namespace SaniTube\Storage\Testing;

use DateTimeImmutable;
use DateTimeInterface;
use SaniTube\Storage\Contracts\StorageProvider;
use SaniTube\Storage\Contracts\SupportsDirectUpload;
use SaniTube\Storage\Exceptions\StorageOperationFailed;
use SaniTube\Storage\Exceptions\TemporaryUrlsUnsupported;
use SaniTube\Storage\ObjectPrefix;
use SaniTube\Storage\PresignedUpload;
use SaniTube\Storage\StorageHealth;
use SaniTube\Storage\StoredObject;

/**
 * An object store that behaves like a private S3-compatible bucket, in memory.
 *
 * It exists so the storage contract has a second implementation that owes
 * nothing to Flysystem. A contract with one implementation is a description of
 * that implementation; the parts of the asset workflow that must hold for
 * *any* provider are proved here as well as against a real disk.
 *
 * It is also the only place expiring URLs can be tested honestly. A signed URL
 * from this provider carries a real expiry and a real signature, and
 * {@see isTemporaryUrlValid()} checks both — so "the URL expires" is a test
 * that can fail, rather than an assertion that a query string contains the
 * word `expires`.
 *
 * Lives in `src/` rather than `tests/` deliberately: it is shipped
 * infrastructure that a future integration suite and local development can
 * both use.
 */
final class InMemoryStorageProvider implements StorageProvider, SupportsDirectUpload
{
    /** @var array<string, string> */
    private array $objects = [];

    /** @var array<string, int> */
    private array $modified = [];

    /** Set when the store is made to fail, to prove failures are handled. */
    private ?string $failure = null;

    public function __construct(
        private readonly string $name = 'memory',
        private readonly ?string $baseUrl = null,
        private readonly string $signingKey = 'in-memory-signing-key',
        private readonly bool $temporaryUrls = true,
    ) {}

    // ------------------------------------------------------ test controls

    /**
     * Make every subsequent operation fail.
     *
     * Storage that is merely absent is easy to handle. Storage that accepts a
     * request and then fails is what leaves an asset half-written, and that is
     * the case worth testing.
     */
    public function failWith(string $message): void
    {
        $this->failure = $message;
    }

    public function recover(): void
    {
        $this->failure = null;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        $keys = array_keys($this->objects);
        sort($keys);

        return $keys;
    }

    public function isTemporaryUrlValid(string $url, ?DateTimeInterface $at = null): bool
    {
        $query = (string) parse_url($url, PHP_URL_QUERY);
        $path = ltrim((string) parse_url($url, PHP_URL_PATH), '/');

        parse_str($query, $parameters);

        $expires = $parameters['expires'] ?? null;
        $signature = $parameters['signature'] ?? null;

        if (! is_string($expires) || ! is_string($signature)) {
            return false;
        }

        if (! hash_equals($this->sign($path, (int) $expires), $signature)) {
            return false;
        }

        return (int) $expires > ($at ?? new DateTimeImmutable)->getTimestamp();
    }

    // ---------------------------------------------------------- contract

    public function name(): string
    {
        return $this->name;
    }

    public function supportsTemporaryUrls(): bool
    {
        return $this->temporaryUrls;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function put(string $key, mixed $contents, array $options = []): StoredObject
    {
        $this->guard();

        if (is_resource($contents)) {
            $contents = (string) stream_get_contents($contents);
        }

        if (! is_string($contents)) {
            throw StorageOperationFailed::write($this->name, $key);
        }

        $this->write($key, $contents);

        return $this->describe($key);
    }

    /**
     * @param  resource  $stream
     * @param  array<string, mixed>  $options
     */
    public function putStream(string $key, $stream, array $options = []): StoredObject
    {
        $this->guard();

        $contents = stream_get_contents($stream);

        if ($contents === false) {
            throw StorageOperationFailed::write($this->name, $key);
        }

        $this->write($key, $contents);

        return $this->describe($key);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function putFile(string $key, string $localPath, array $options = []): StoredObject
    {
        $contents = @file_get_contents($localPath);

        if ($contents === false) {
            throw StorageOperationFailed::unreadableSource($localPath);
        }

        return $this->put($key, $contents, $options);
    }

    public function get(string $key): string
    {
        $this->guard();

        return $this->objects[$key] ?? throw StorageOperationFailed::read($this->name, $key);
    }

    public function readStream(string $key)
    {
        $contents = $this->get($key);

        $stream = fopen('php://memory', 'r+');

        if ($stream === false) {
            throw StorageOperationFailed::read($this->name, $key);
        }

        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }

    public function exists(string $key): bool
    {
        $this->guard();

        return isset($this->objects[$key]);
    }

    public function delete(string $key): bool
    {
        $this->guard();

        if (! isset($this->objects[$key])) {
            return false;
        }

        unset($this->objects[$key], $this->modified[$key]);

        return true;
    }

    public function size(string $key): int
    {
        return strlen($this->get($key));
    }

    public function lastModified(string $key): int
    {
        $this->guard();

        return $this->modified[$key] ?? throw StorageOperationFailed::read($this->name, $key);
    }

    public function files(string $prefix = ''): array
    {
        $this->guard();

        $scope = ObjectPrefix::normalise($prefix);

        $keys = array_values(array_filter(
            array_keys($this->objects),
            static fn (string $key): bool => ObjectPrefix::contains($scope, $key),
        ));

        sort($keys);

        return $keys;
    }

    public function checksum(string $key, string $algorithm = 'sha256'): string
    {
        return hash($algorithm, $this->get($key));
    }

    public function move(string $from, string $to): StoredObject
    {
        $this->guard();

        if (! isset($this->objects[$from])) {
            throw StorageOperationFailed::move($this->name, $from, $to);
        }

        $this->write($to, $this->objects[$from]);
        unset($this->objects[$from], $this->modified[$from]);

        return $this->describe($to);
    }

    public function temporaryUrl(string $key, DateTimeInterface $expiresAt): string
    {
        if (! $this->temporaryUrls) {
            throw TemporaryUrlsUnsupported::for($this->name);
        }

        $expires = $expiresAt->getTimestamp();

        return sprintf(
            '%s/%s?expires=%d&signature=%s',
            $this->baseUrl(),
            ltrim($key, '/'),
            $expires,
            $this->sign(ltrim($key, '/'), $expires),
        );
    }

    public function healthCheck(): StorageHealth
    {
        if ($this->failure !== null) {
            return StorageHealth::failing(
                $this->name,
                ['write' => false, 'read' => false, 'delete' => false],
                $this->temporaryUrls,
                $this->failure,
            );
        }

        return StorageHealth::passing(
            $this->name,
            ['write' => true, 'read' => true, 'delete' => true],
            $this->temporaryUrls,
        );
    }

    // ----------------------------------------------------------- internals

    /**
     * Backdates an object, so abandoned-upload cleanup can be tested without
     * waiting a day for it to become true.
     */
    public function backdate(string $key, int $timestamp): void
    {
        $this->modified[$key] = $timestamp;
    }

    private function write(string $key, string $contents): void
    {
        $this->objects[$key] = $contents;
        $this->modified[$key] = time();
    }

    private function describe(string $key): StoredObject
    {
        return new StoredObject(
            provider: $this->name,
            key: $key,
            size: strlen($this->objects[$key]),
        );
    }

    /**
     * Signed URLs follow the installation's configured URL, exactly as a real
     * provider's follow its endpoint. Nothing here names a host.
     */
    private function baseUrl(): string
    {
        return rtrim($this->baseUrl ?? (string) config('app.url', 'http://localhost'), '/');
    }

    private function sign(string $key, int $expires): string
    {
        return hash_hmac('sha256', $key.'|'.$expires, $this->signingKey);
    }

    private function guard(): void
    {
        if ($this->failure !== null) {
            throw new StorageOperationFailed($this->failure);
        }
    }

    /**
     * A deterministic stand-in for a signed upload URL.
     *
     * It is not a permission — nothing here checks it — and that is the point:
     * the tests that matter assert what the *server* does with what arrives in
     * staging, not that a signature was well formed. Signature construction
     * belongs to the SDK and is not this platform's to re-prove.
     *
     * There is deliberately no flag to make this double *refuse*. A provider
     * that declares the capability and then throws is precisely the promise
     * nobody kept that {@see SupportsDirectUpload} exists to prevent — so the
     * other case is tested against a provider that genuinely does not
     * implement it, which is the local disk.
     */
    public function temporaryUploadUrl(string $key, DateTimeInterface $expiresAt): PresignedUpload
    {
        return new PresignedUpload(
            url: sprintf('memory://%s/%s?upload=1', $this->name, $key),
            headers: ['Content-Type' => 'application/octet-stream'],
            expiresAt: $expiresAt->format(DATE_ATOM),
        );
    }
}
