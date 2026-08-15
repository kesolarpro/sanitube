<?php

declare(strict_types=1);

namespace Tests\Feature\Ingestion;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Ingestion\Jobs\ProcessIngestionItemJob;
use SaniTube\Ingestion\Models\IngestionBatch;
use SaniTube\Storage\StorageManager;
use SaniTube\Storage\Testing\InMemoryStorageProvider;
use Tests\TestCase;

/**
 * Starting an import of nine hundred files without a browser.
 *
 * The reason this command exists is the reason these tests check what they
 * check: the work must be *queued*, not done, so that the operator can close
 * the terminal. A command that imported nine hundred files inline would be a
 * command nobody could finish running.
 */
final class ImportCommandTest extends TestCase
{
    use RefreshDatabase;

    private InMemoryStorageProvider $store;

    private string $manifestPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new InMemoryStorageProvider('memory');
        config(['storage.default' => 'memory']);
        $this->app->make(StorageManager::class)->register('memory', $this->store);

        $this->manifestPath = tempnam(sys_get_temp_dir(), 'sanitube-manifest-').'.csv';

        Queue::fake();
    }

    protected function tearDown(): void
    {
        if (is_file($this->manifestPath)) {
            unlink($this->manifestPath);
        }

        parent::tearDown();
    }

    #[Test]
    public function it_starts_a_batch_from_a_manifest_and_queues_the_work(): void
    {
        $this->store->put('library/01.wav', 'one');
        $this->store->put('library/02.wav', 'two');

        $this->writeManifest(<<<'CSV'
            reference,title
            library/01.wav,One
            library/02.wav,Two
            CSV);

        $this->artisan('sanitube:import', ['--manifest' => $this->manifestPath])
            ->expectsOutputToContain('2 row(s) accepted, 0 refused')
            ->assertSuccessful();

        $batch = IngestionBatch::query()->firstOrFail();
        $this->assertSame(2, $batch->total());

        // Queued, not done. This is the whole point of the command.
        Queue::assertPushed(ProcessIngestionItemJob::class, 2);
    }

    #[Test]
    public function it_starts_a_batch_from_explicit_references(): void
    {
        $this->store->put('library/01.wav', 'one');

        $this->artisan('sanitube:import', ['--reference' => ['library/01.wav']])
            ->assertSuccessful();

        $this->assertSame(1, IngestionBatch::query()->firstOrFail()->total());
    }

    #[Test]
    public function it_reports_every_refused_row_with_its_line_number(): void
    {
        $this->store->put('library/01.wav', 'one');

        $this->writeManifest(<<<'CSV'
            reference,title,isrc
            library/01.wav,One,
            library/02.wav,Two,NOT-AN-ISRC
            CSV);

        $this->artisan('sanitube:import', ['--manifest' => $this->manifestPath])
            ->expectsOutputToContain('1 row(s) accepted, 1 refused')
            ->expectsOutputToContain('MALFORMED_IDENTIFIER')
            ->assertSuccessful();

        // The good row still imported. Three bad lines out of nine hundred
        // must not cost the operator the other eight hundred and ninety seven.
        $this->assertSame(1, IngestionBatch::query()->firstOrFail()->total());
    }

    #[Test]
    public function a_manifest_where_every_row_is_refused_fails_rather_than_starting_an_empty_batch(): void
    {
        $this->writeManifest("reference,isrc\nlibrary/01.wav,NOPE\n");

        $this->artisan('sanitube:import', ['--manifest' => $this->manifestPath])
            ->assertFailed();

        $this->assertSame(0, IngestionBatch::query()->count());
    }

    #[Test]
    public function a_dry_run_writes_nothing_and_queues_nothing(): void
    {
        $this->store->put('library/01.wav', 'one');

        $this->writeManifest("reference,title\nlibrary/01.wav,One\n");

        $this->artisan('sanitube:import', [
            '--manifest' => $this->manifestPath,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('nothing was written')
            ->assertSuccessful();

        $this->assertSame(0, IngestionBatch::query()->count());
        Queue::assertNothingPushed();
    }

    #[Test]
    public function a_dry_run_says_how_many_it_did_not_list(): void
    {
        $csv = "reference,title\n";

        for ($i = 1; $i <= 25; $i++) {
            $csv .= sprintf("library/%02d.wav,Track %d\n", $i, $i);
        }

        $this->writeManifest($csv);

        // A truncated list that looks complete is how somebody concludes a
        // batch is smaller than it is.
        $this->artisan('sanitube:import', [
            '--manifest' => $this->manifestPath,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('and 5 more not shown')
            ->assertSuccessful();
    }

    #[Test]
    public function naming_nothing_is_refused(): void
    {
        $this->artisan('sanitube:import')->assertExitCode(2);

        $this->assertSame(0, IngestionBatch::query()->count());
    }

    #[Test]
    public function naming_two_things_to_import_is_refused(): void
    {
        $this->writeManifest("reference,title\nlibrary/01.wav,One\n");

        // Two things naming the batch with no rule for which wins is how an
        // import quietly does something nobody asked for.
        $this->artisan('sanitube:import', [
            '--manifest' => $this->manifestPath,
            '--prefix' => 'library',
        ])->assertExitCode(2);

        $this->assertSame(0, IngestionBatch::query()->count());
    }

    #[Test]
    public function a_source_that_is_declared_but_not_implemented_is_refused_by_name(): void
    {
        $this->artisan('sanitube:import', [
            '--source' => 'LEGACY_IMPORT',
            '--reference' => ['library/01.wav'],
        ])
            ->expectsOutputToContain('LEGACY_IMPORT')
            ->assertExitCode(2);
    }

    #[Test]
    public function a_manifest_that_cannot_be_read_is_reported_rather_than_thrown(): void
    {
        $this->artisan('sanitube:import', ['--manifest' => '/nonexistent/manifest.csv'])
            ->assertFailed();
    }

    private function writeManifest(string $csv): void
    {
        file_put_contents($this->manifestPath, $csv);
    }
}
