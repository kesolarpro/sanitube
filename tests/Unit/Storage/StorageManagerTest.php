<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Storage\Exceptions\UnknownStorageProvider;
use SaniTube\Storage\Providers\BackblazeB2Provider;
use SaniTube\Storage\Providers\CloudflareR2Provider;
use SaniTube\Storage\Providers\LocalStorageProvider;
use SaniTube\Storage\Providers\S3StorageProvider;
use SaniTube\Storage\StorageManager;
use Tests\TestCase;

final class StorageManagerTest extends TestCase
{
    private function manager(string $default = 'local'): StorageManager
    {
        return new StorageManager(
            filesystems: $this->app->make(FilesystemFactory::class),
            providers: [
                'local' => ['driver' => 'local', 'disk' => 'local'],
                's3' => ['driver' => 's3', 'disk' => 'local'],
                'r2' => ['driver' => 'r2', 'disk' => 'local'],
                'b2' => ['driver' => 'b2', 'disk' => 'local'],
            ],
            defaultProvider: $default,
        );
    }

    #[Test]
    public function it_resolves_each_driver_to_its_provider(): void
    {
        $manager = $this->manager();

        $this->assertInstanceOf(LocalStorageProvider::class, $manager->provider('local'));
        $this->assertInstanceOf(S3StorageProvider::class, $manager->provider('s3'));
        $this->assertInstanceOf(CloudflareR2Provider::class, $manager->provider('r2'));
        $this->assertInstanceOf(BackblazeB2Provider::class, $manager->provider('b2'));
    }

    #[Test]
    public function providers_are_resolved_once_and_reused(): void
    {
        $manager = $this->manager();

        $this->assertSame($manager->provider('s3'), $manager->provider('s3'));
    }

    #[Test]
    public function the_default_provider_comes_from_configuration(): void
    {
        $manager = $this->manager(default: 'r2');

        $this->assertSame('r2', $manager->defaultName());
        $this->assertInstanceOf(CloudflareR2Provider::class, $manager->default());
    }

    #[Test]
    public function an_unconfigured_provider_names_the_configured_ones(): void
    {
        $this->expectException(UnknownStorageProvider::class);
        $this->expectExceptionMessage('local, s3, r2, b2');

        $this->manager()->provider('dropbox');
    }

    #[Test]
    public function it_reports_which_providers_are_configured(): void
    {
        $manager = $this->manager();

        $this->assertTrue($manager->has('b2'));
        $this->assertFalse($manager->has('dropbox'));
        $this->assertSame(['local', 's3', 'r2', 'b2'], $manager->names());
    }
}
