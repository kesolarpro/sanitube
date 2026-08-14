<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Storage\Exceptions\TemporaryUrlsUnsupported;
use SaniTube\Storage\Providers\LocalStorageProvider;
use SaniTube\Storage\Providers\S3StorageProvider;
use Tests\TestCase;

final class StorageProviderTest extends TestCase
{
    #[Test]
    public function it_stores_contents_and_describes_the_stored_object(): void
    {
        $provider = new LocalStorageProvider(Storage::fake('assets'), 'local');

        $object = $provider->put('masters/track.txt', 'audio-bytes');

        $this->assertSame('local', $object->provider);
        $this->assertSame('masters/track.txt', $object->key);
        $this->assertSame(11, $object->size);
        $this->assertTrue($provider->exists('masters/track.txt'));
        $this->assertSame('audio-bytes', $provider->get('masters/track.txt'));
    }

    #[Test]
    public function it_streams_a_local_file_rather_than_loading_it_into_memory(): void
    {
        $provider = new LocalStorageProvider(Storage::fake('assets'), 'local');

        $source = tempnam(sys_get_temp_dir(), 'sanitube');
        file_put_contents($source, str_repeat('x', 2048));

        try {
            $object = $provider->putFile('masters/import.wav', $source);
        } finally {
            @unlink($source);
        }

        $this->assertSame(2048, $object->size);
        $this->assertSame(2048, $provider->size('masters/import.wav'));
    }

    #[Test]
    public function a_missing_source_file_fails_loudly(): void
    {
        $provider = new LocalStorageProvider(Storage::fake('assets'), 'local');

        $this->expectExceptionMessage('could not be opened for reading');

        $provider->putFile('masters/import.wav', '/nonexistent/path/track.wav');
    }

    #[Test]
    public function it_reads_a_stream_back(): void
    {
        $provider = new LocalStorageProvider(Storage::fake('assets'), 'local');
        $provider->put('artwork/cover.txt', 'cover-bytes');

        $stream = $provider->readStream('artwork/cover.txt');

        $this->assertIsResource($stream);
        $this->assertSame('cover-bytes', stream_get_contents($stream));
        fclose($stream);
    }

    #[Test]
    public function it_deletes_objects(): void
    {
        $provider = new LocalStorageProvider(Storage::fake('assets'), 'local');
        $provider->put('tmp/scratch.txt', 'x');

        $this->assertTrue($provider->delete('tmp/scratch.txt'));
        $this->assertFalse($provider->exists('tmp/scratch.txt'));
    }

    #[Test]
    public function the_local_provider_refuses_to_pretend_it_can_sign_urls(): void
    {
        // Playback and distributor hand-off depend on expiring URLs. A local
        // disk cannot mint them, and must say so instead of returning a
        // permanent URL to a master.
        $provider = new LocalStorageProvider(Storage::fake('assets'), 'local');
        $provider->put('masters/track.wav', 'audio');

        $this->assertFalse($provider->supportsTemporaryUrls());

        $this->expectException(TemporaryUrlsUnsupported::class);

        $provider->temporaryUrl('masters/track.wav', now()->addMinutes(15));
    }

    #[Test]
    public function s3_compatible_providers_advertise_temporary_urls(): void
    {
        $provider = new S3StorageProvider(Storage::fake('assets'), 's3');

        $this->assertTrue($provider->supportsTemporaryUrls());
        $this->assertSame('s3', $provider->name());
    }
}
