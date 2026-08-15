<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SaniTube\Storage\Contracts\StorageProvider;
use SplFileInfo;
use Tests\TestCase;

/**
 * The domain must not know where its bytes live.
 *
 * Storage vendors leak the same way distributors do — not by decision, but by
 * a call to `Storage::disk('s3')` in an import job, a class named after a
 * bucket, a config key that assumes an endpoint. Once the domain knows the
 * provider, changing provider stops being a configuration change and becomes a
 * migration.
 *
 * Vendor names belong in exactly two places: the adapters under `src/Storage`,
 * whose whole job is to name one, and `config/`, which is data.
 */
final class StorageBoundaryTest extends TestCase
{
    /**
     * Vocabulary that must not appear outside the adapter layer.
     *
     * @var list<string>
     */
    private const FORBIDDEN = ['s3', 'r2', 'backblaze', 'b2', 'aws', 'cloudflare', 'bucket'];

    /**
     * Business code. `src/Storage` is deliberately absent: it is the adapter
     * layer, and naming providers is its purpose.
     *
     * @var list<string>
     */
    private const DOMAIN_PATHS = [
        'src/Artists',
        'src/Assets',
        'src/Catalog',
        'src/Contributors',
        'src/Releases',
        'database/factories',
        'database/seeders',
    ];

    #[Test]
    public function no_storage_vendor_is_named_in_the_domain(): void
    {
        $offenders = [];

        foreach ($this->domainFiles() as $file) {
            $contents = strtolower((string) file_get_contents($file->getPathname()));

            foreach (self::FORBIDDEN as $needle) {
                // The lookbehind matters as much as the word boundary. PHP's
                // own stream API is built on "buckets" — stream_bucket_append,
                // $bucket — and that vocabulary has nothing to do with object
                // storage. Only a standalone token counts.
                if (preg_match('/(?<![a-z0-9_$])'.preg_quote($needle, '/').'\b/', $contents) === 1) {
                    $offenders[] = sprintf('%s names [%s]', $this->relative($file), $needle);
                }
            }
        }

        $this->assertSame([], $offenders, 'Storage vendor vocabulary leaked into the domain: '.implode('; ', $offenders));
    }

    #[Test]
    public function the_domain_never_reaches_for_a_disk_directly(): void
    {
        // The specific mistake this prevents: an import job calling
        // Storage::disk('s3') and pinning the catalogue to one provider.
        $offenders = [];

        foreach ($this->domainFiles() as $file) {
            $contents = (string) file_get_contents($file->getPathname());

            foreach (['Storage::disk(', 'Storage::cloud(', 'Illuminate\Support\Facades\Storage'] as $needle) {
                if (str_contains($contents, $needle)) {
                    $offenders[] = sprintf('%s uses [%s]', $this->relative($file), $needle);
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'The domain must go through StorageProvider, not the filesystem facade: '.implode('; ', $offenders),
        );
    }

    #[Test]
    public function the_contract_carries_everything_the_asset_workflow_needs(): void
    {
        // Stated as a test because the alternative — an adapter offering a
        // method the contract does not — is how domain code ends up depending
        // on one implementation.
        foreach ([
            'putStream', 'readStream', 'exists', 'size', 'checksum',
            'move', 'delete', 'temporaryUrl', 'healthCheck', 'files', 'lastModified',
        ] as $method) {
            $this->assertTrue(
                method_exists(StorageProvider::class, $method),
                sprintf('StorageProvider is missing %s().', $method),
            );
        }
    }

    #[Test]
    public function every_shipped_provider_implements_the_whole_contract(): void
    {
        $directory = base_path('src/Storage/Providers');
        $found = 0;

        foreach ((array) glob($directory.'/*.php') as $path) {
            $class = 'SaniTube\\Storage\\Providers\\'.basename((string) $path, '.php');

            $this->assertTrue(is_a($class, StorageProvider::class, true), $class.' is not a StorageProvider.');
            $found++;
        }

        $this->assertGreaterThan(0, $found);
    }

    /**
     * @return list<SplFileInfo>
     */
    private function domainFiles(): array
    {
        $files = [];

        foreach (self::DOMAIN_PATHS as $path) {
            $directory = base_path($path);

            if (! is_dir($directory)) {
                continue;
            }

            /** @var SplFileInfo $file */
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)) as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file;
                }
            }
        }

        return $files;
    }

    private function relative(SplFileInfo $file): string
    {
        return str_replace(base_path().'/', '', $file->getPathname());
    }
}
