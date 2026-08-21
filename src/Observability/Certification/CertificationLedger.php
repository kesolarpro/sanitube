<?php

declare(strict_types=1);

namespace SaniTube\Observability\Certification;

use Illuminate\Support\Carbon;
use SaniTube\Storage\Console\StorageCheckCommand;

/**
 * The record of real certification runs — the only way CERTIFIED happens.
 *
 * A certification is a fact about a moment *and a configuration*: "the
 * bucket answered every check" stops being true the moment the bucket, the
 * endpoint or the driver changes. So every record carries a fingerprint of
 * the configuration it certified — identity fields only, never a secret —
 * and a record whose fingerprint no longer matches is not consulted. It is
 * not deleted either: it is the history of what was once proven.
 *
 * Nothing here runs a check. The commands that talk to real services
 * ({@see StorageCheckCommand} with --certify, the
 * worker check) record their passes; this file only remembers them.
 */
final readonly class CertificationLedger
{
    private const FILE = 'framework/certifications.json';

    public function __construct(private string $storagePath) {}

    public function record(string $key, string $fingerprint): void
    {
        $entries = $this->load();

        $entries[$key] = [
            'fingerprint' => $fingerprint,
            'certified_at' => Carbon::now()->toAtomString(),
        ];

        $path = $this->path();
        $directory = dirname($path);

        if (! is_dir($directory)) {
            @mkdir($directory, 0755, true);
        }

        $encoded = json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($encoded === false) {
            return;
        }

        $temporary = $path.'.tmp';

        if (@file_put_contents($temporary, $encoded."\n") !== false) {
            @rename($temporary, $path);
        }
    }

    /**
     * When this key was certified for this exact configuration, or null —
     * including when it was certified for a configuration that has since
     * changed, which is the same as never for anything that matters.
     */
    public function certifiedAt(string $key, string $fingerprint): ?string
    {
        $entry = $this->load()[$key] ?? null;

        if (! is_array($entry)) {
            return null;
        }

        if (($entry['fingerprint'] ?? null) !== $fingerprint) {
            return null;
        }

        $at = $entry['certified_at'] ?? null;

        return is_string($at) ? $at : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function load(): array
    {
        $raw = @file_get_contents($this->path());

        if ($raw === false) {
            return [];
        }

        $decoded = json_decode($raw, true);

        /** @var array<string, mixed> */
        return is_array($decoded) ? $decoded : [];
    }

    private function path(): string
    {
        return rtrim($this->storagePath, '/').'/'.self::FILE;
    }
}
