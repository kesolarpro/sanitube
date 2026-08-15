<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Storage\Contracts\StorageProvider;
use SaniTube\Storage\Exceptions\StorageOperationFailed;
use SaniTube\Storage\Providers\LocalStorageProvider;
use SaniTube\Storage\Providers\S3StorageProvider;
use SaniTube\Storage\Testing\InMemoryStorageProvider;
use Tests\TestCase;

/**
 * Everything the storage contract promises, asked of every implementation.
 *
 * A contract with one implementation is a description of that implementation.
 * These run against a real disk *and* against an in-memory store that owes
 * nothing to Flysystem, so behaviour the asset workflow depends on cannot
 * quietly become "whatever Flysystem happens to do".
 */
final class StorageProviderContractTest extends TestCase
{
    /**
     * @return iterable<string, array{callable(): StorageProvider}>
     */
    public static function providers(): iterable
    {
        yield 'local disk' => [fn (): StorageProvider => new LocalStorageProvider(Storage::fake('contract'), 'local')];
        yield 'in memory' => [fn (): StorageProvider => new InMemoryStorageProvider];
    }

    #[Test]
    #[DataProvider('providers')]
    public function it_stores_a_stream_without_buffering_it(callable $make): void
    {
        $provider = $make();

        $object = $provider->putStream('masters/track/original.wav', $this->streamOf(str_repeat('x', 4096)));

        $this->assertSame(4096, $object->size);
        $this->assertSame(4096, $provider->size('masters/track/original.wav'));
    }

    #[Test]
    #[DataProvider('providers')]
    public function it_hashes_what_is_actually_in_storage(callable $make): void
    {
        // The distinction the whole workflow rests on: this is the hash of the
        // object, not of what was sent.
        $provider = $make();
        $contents = str_repeat('audio', 1000);

        $provider->put('masters/track/original.wav', $contents);

        $this->assertSame(hash('sha256', $contents), $provider->checksum('masters/track/original.wav'));
    }

    #[Test]
    #[DataProvider('providers')]
    public function it_moves_an_object_leaving_nothing_behind(callable $make): void
    {
        $provider = $make();
        $provider->put('staging/track/original.wav', 'bytes');

        $object = $provider->move('staging/track/original.wav', 'masters/track/original.wav');

        $this->assertSame('masters/track/original.wav', $object->key);
        $this->assertTrue($provider->exists('masters/track/original.wav'));
        $this->assertFalse($provider->exists('staging/track/original.wav'));
    }

    #[Test]
    #[DataProvider('providers')]
    public function moving_something_that_is_not_there_fails_loudly(callable $make): void
    {
        $provider = $make();

        $this->expectException(StorageOperationFailed::class);

        $provider->move('staging/missing/original.wav', 'masters/missing/original.wav');
    }

    #[Test]
    #[DataProvider('providers')]
    public function it_lists_only_what_is_under_the_given_prefix(callable $make): void
    {
        // The staging sweep depends on this: a listing that reaches past its
        // prefix is a sweep that can delete a master.
        $provider = $make();
        $provider->put('staging/a/original.wav', 'a');
        $provider->put('staging/b/original.wav', 'b');
        $provider->put('masters/c/original.wav', 'c');

        $listed = $provider->files('staging');

        $this->assertCount(2, $listed);

        foreach ($listed as $key) {
            $this->assertStringStartsWith('staging/', $key);
        }
    }

    #[Test]
    #[DataProvider('providers')]
    public function a_listing_never_reaches_past_its_prefix(callable $make): void
    {
        // `staging-archive/` shares a prefix with `staging/` as a *string* and
        // is a different place. The provider must draw that line itself: the
        // staging sweep is downstream of this, and a caller that has to verify
        // its own listing is a caller whose safety depends on remembering to.
        $provider = $make();
        $provider->put('staging/a/original.wav', 'a');
        $provider->put('staging-archive/b/original.wav', 'b');
        $provider->put('masters/c/original.wav', 'c');

        $this->assertSame(['staging/a/original.wav'], $provider->files('staging'));
    }

    #[Test]
    #[DataProvider('providers')]
    public function a_prefix_is_normalised_rather_than_taken_literally(callable $make): void
    {
        $provider = $make();
        $provider->put('staging/a/original.wav', 'a');

        // The same place, however it is spelled.
        $this->assertSame(['staging/a/original.wav'], $provider->files('staging'));
        $this->assertSame(['staging/a/original.wav'], $provider->files('staging/'));
        $this->assertSame(['staging/a/original.wav'], $provider->files('/staging/'));
    }

    #[Test]
    #[DataProvider('providers')]
    public function a_prefix_that_tries_to_climb_out_is_refused(callable $make): void
    {
        // Rejected rather than sanitised. A prefix that needs cleaning up is a
        // prefix whose author was confused about the boundary.
        $provider = $make();

        $this->expectException(StorageOperationFailed::class);
        $this->expectExceptionMessage('may not contain traversal');

        $provider->files('staging/../masters');
    }

    #[Test]
    #[DataProvider('providers')]
    public function it_reports_when_an_object_was_last_written(callable $make): void
    {
        $provider = $make();
        $provider->put('staging/a/original.wav', 'a');

        $this->assertGreaterThan(0, $provider->lastModified('staging/a/original.wav'));
    }

    #[Test]
    #[DataProvider('providers')]
    public function a_health_check_writes_reads_and_deletes_for_real(callable $make): void
    {
        $provider = $make();

        $health = $provider->healthCheck();

        $this->assertTrue($health->healthy, (string) $health->detail);
        $this->assertSame([], $health->failedChecks());
        $this->assertSame($provider->name(), $health->provider);
    }

    #[Test]
    #[DataProvider('providers')]
    public function a_health_check_leaves_no_probe_object_behind(callable $make): void
    {
        $provider = $make();
        $provider->healthCheck();

        $this->assertSame([], $provider->files('.sanitube-health'));
    }

    // ------------------------------------------------ provider differences

    #[Test]
    public function the_local_provider_refuses_to_pretend_it_can_sign_urls(): void
    {
        // Playback and distributor hand-off depend on expiring URLs. A local
        // disk cannot mint them, and must say so instead of returning a
        // permanent URL to a master.
        $provider = new LocalStorageProvider(Storage::fake('contract'), 'local');
        $provider->put('masters/track/original.wav', 'audio');

        $this->assertFalse($provider->supportsTemporaryUrls());

        $this->expectExceptionMessage('does not support temporary URLs');

        $provider->temporaryUrl('masters/track/original.wav', now()->addMinutes(15));
    }

    #[Test]
    public function s3_compatible_providers_advertise_temporary_urls(): void
    {
        $provider = new S3StorageProvider(Storage::fake('contract'), 's3');

        $this->assertTrue($provider->supportsTemporaryUrls());
        $this->assertSame('s3', $provider->name());
    }

    #[Test]
    public function a_health_check_reports_a_provider_that_is_down(): void
    {
        $provider = new InMemoryStorageProvider;
        $provider->failWith('The endpoint refused the connection.');

        $health = $provider->healthCheck();

        $this->assertFalse($health->healthy);
        $this->assertSame(['write', 'read', 'delete'], $health->failedChecks());
    }

    #[Test]
    public function a_missing_source_file_fails_loudly(): void
    {
        $provider = new LocalStorageProvider(Storage::fake('contract'), 'local');

        $this->expectExceptionMessage('could not be opened for reading');

        $provider->putFile('masters/import.wav', '/nonexistent/path/track.wav');
    }

    #[Test]
    public function it_reads_a_stream_back(): void
    {
        $provider = new LocalStorageProvider(Storage::fake('contract'), 'local');
        $provider->put('artwork/cover.txt', 'cover-bytes');

        $stream = $provider->readStream('artwork/cover.txt');

        $this->assertSame('cover-bytes', stream_get_contents($stream));
        fclose($stream);
    }

    /**
     * @return resource
     */
    private function streamOf(string $contents)
    {
        $stream = fopen('php://memory', 'r+');

        if ($stream === false) {
            $this->fail('Could not open a memory stream.');
        }

        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }
}
