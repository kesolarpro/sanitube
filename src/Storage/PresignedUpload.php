<?php

declare(strict_types=1);

namespace SaniTube\Storage;

/**
 * A short-lived permission to write one object, and what the request must carry.
 *
 * The headers are not decoration. An S3-compatible presigned PUT signs the
 * request it expects, so a browser that omits a signed header — or adds one —
 * gets a signature mismatch after uploading the whole file. Returning the URL
 * alone would leave every caller to rediscover that, one two-gigabyte upload
 * at a time.
 *
 * Nothing here is secret in the sense that it needs hiding from the person
 * uploading: the signature *is* the permission, it is scoped to one key, and
 * it expires in minutes. It is still not something to log, because a log is
 * read later and by more people than the person holding the file.
 */
final readonly class PresignedUpload
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public string $url,
        public array $headers,
        public string $expiresAt,
    ) {}

    /**
     * What the browser is told.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['url' => $this->url, 'headers' => $this->headers, 'expires_at' => $this->expiresAt];
    }
}
