<?php

declare(strict_types=1);

namespace Tests\Feature\Deployment;

use PHPUnit\Framework\Attributes\Test;
use SaniTube\Deployment\Exceptions\FrontendBuildException;
use SaniTube\Deployment\Frontend\FrontendBuildInstaller;
use Tests\TestCase;
use ZipArchive;

/**
 * The frontend arrives built, and installing it can neither break the site
 * nor be used to write anywhere else.
 *
 * DEP-011. Production hosts have no Node; the bundle is CI's artifact — the
 * exact contents of public/build — installed from a zip or a directory.
 * These tests hold the three rules: atomic or nothing (a failed install
 * leaves the old build serving), the archive is hostile until proven boring
 * (traversal and symlinks refuse with nothing written), and a build belongs
 * to a commit (a mismatch is refused with both values named).
 *
 * One branch is knowingly unheld: the restore inside swap() that puts the
 * old build back when the *second* rename fails. Forcing that failure means
 * making the public directory unwritable between two renames, and the suite
 * often runs as root, where permission bits do not bind. Named here so
 * nobody counts it as covered.
 */
final class FrontendBuildTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = storage_path('framework/testing/frontend-'.uniqid());
        mkdir($this->root.'/public', 0755, true);
        mkdir($this->root.'/state', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);

        parent::tearDown();
    }

    // ------------------------------------------------------------ install

    #[Test]
    public function a_directory_build_is_installed_and_recorded(): void
    {
        $source = $this->buildDirectory(['assets/app-abc123.js' => 'js']);

        $installed = $this->installer()->install($source, null);

        $this->assertSame(1, $installed['entries']);
        $this->assertFileExists($this->root.'/public/build/manifest.json');
        $this->assertFileExists($this->root.'/public/build/assets/app-abc123.js');
        $this->assertFileExists($this->root.'/state/frontend-build.json');
    }

    #[Test]
    public function a_zip_build_is_installed_the_same_way(): void
    {
        $archive = $this->buildZip([
            'manifest.json' => (string) json_encode(['resources/js/app.ts' => ['file' => 'assets/app-abc.js']]),
            'assets/app-abc.js' => 'js',
        ]);

        $this->installer()->install($archive, null);

        $this->assertFileExists($this->root.'/public/build/assets/app-abc.js');
    }

    #[Test]
    public function the_old_build_is_replaced_whole_so_stale_hashes_cannot_linger(): void
    {
        mkdir($this->root.'/public/build/assets', 0755, true);
        file_put_contents($this->root.'/public/build/manifest.json', '{"old": {"file": "assets/old-999.js"}}');
        file_put_contents($this->root.'/public/build/assets/old-999.js', 'stale');

        $this->installer()->install($this->buildDirectory(['assets/new-111.js' => 'fresh']), null);

        $this->assertFileExists($this->root.'/public/build/assets/new-111.js');
        $this->assertFileDoesNotExist($this->root.'/public/build/assets/old-999.js');
    }

    #[Test]
    public function a_source_without_a_manifest_leaves_the_live_build_untouched(): void
    {
        mkdir($this->root.'/public/build', 0755, true);
        file_put_contents($this->root.'/public/build/manifest.json', '{"live": {"file": "a.js"}}');

        $bare = $this->root.'/bare';
        mkdir($bare.'/assets', 0755, true);
        file_put_contents($bare.'/assets/app.js', 'js');

        try {
            $this->installer()->install($bare, null);
            $this->fail('A build without a manifest was installed.');
        } catch (FrontendBuildException $exception) {
            $this->assertSame('BUILD_NO_MANIFEST', $exception->reason);
        }

        // The site still serves what it served, and no staging litter stays.
        $this->assertSame(
            '{"live": {"file": "a.js"}}',
            file_get_contents($this->root.'/public/build/manifest.json'),
        );
        $this->assertSame(['build'], $this->publicEntries());
    }

    #[Test]
    public function an_empty_manifest_is_a_bundle_that_builds_nothing(): void
    {
        try {
            $this->installer()->install($this->buildDirectory([], manifest: '{}'), null);
            $this->fail('An empty manifest was installed.');
        } catch (FrontendBuildException $exception) {
            $this->assertSame('BUILD_EMPTY_MANIFEST', $exception->reason);
        }
    }

    // ------------------------------------------------------------ archive

    #[Test]
    public function an_entry_that_walks_out_of_the_directory_installs_nothing(): void
    {
        $archive = $this->buildZip([
            'manifest.json' => '{"a": {"file": "x.js"}}',
            '../escape.txt' => 'outside',
        ]);

        try {
            $this->installer()->install($archive, null);
            $this->fail('A traversal entry was extracted.');
        } catch (FrontendBuildException $exception) {
            $this->assertSame('BUILD_UNSAFE_ARCHIVE', $exception->reason);
            // The payload path is exactly what must not be echoed.
            $this->assertStringNotContainsString('escape', $exception->getMessage());
        }

        $this->assertFileDoesNotExist($this->root.'/escape.txt');
        $this->assertFileDoesNotExist($this->root.'/public/escape.txt');
        $this->assertSame([], $this->publicEntries());
    }

    #[Test]
    public function an_absolute_entry_is_refused_the_same_way(): void
    {
        $archive = $this->buildZip([
            'manifest.json' => '{"a": {"file": "x.js"}}',
            '/tmp/absolute.txt' => 'outside',
        ]);

        try {
            $this->installer()->install($archive, null);
            $this->fail('An absolute entry was extracted.');
        } catch (FrontendBuildException $exception) {
            $this->assertSame('BUILD_UNSAFE_ARCHIVE', $exception->reason);
        }

        $this->assertFileDoesNotExist('/tmp/absolute.txt');
    }

    #[Test]
    public function a_symlink_in_a_directory_source_is_refused(): void
    {
        $source = $this->buildDirectory(['assets/app.js' => 'js']);
        symlink('/etc/hostname', $source.'/assets/link');

        try {
            $this->installer()->install($source, null);
            $this->fail('A symlinked source entry was accepted.');
        } catch (FrontendBuildException $exception) {
            $this->assertSame('BUILD_UNSAFE_ARCHIVE', $exception->reason);
        }
    }

    // ------------------------------------------------------------- commit

    #[Test]
    public function a_build_from_another_commit_is_refused_with_both_named(): void
    {
        $this->fakeGitAt('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');

        try {
            $this->installer()->install(
                $this->buildDirectory(['assets/app.js' => 'js']),
                'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
            );
            $this->fail('A mismatched build was installed.');
        } catch (FrontendBuildException $exception) {
            $this->assertSame('BUILD_COMMIT_MISMATCH', $exception->reason);
            $this->assertStringContainsString('aaaaaaaa', $exception->getMessage());
            $this->assertStringContainsString('bbbbbbbb', $exception->getMessage());
        }
    }

    #[Test]
    public function a_short_sha_matches_its_own_commit(): void
    {
        $this->fakeGitAt('abcdef1234567890abcdef1234567890abcdef12');

        $installed = $this->installer()->install(
            $this->buildDirectory(['assets/app.js' => 'js']),
            'abcdef12',
        );

        $this->assertSame('abcdef12', $installed['sha']);
    }

    #[Test]
    public function head_is_read_through_the_ref_and_the_packed_refs_fallback(): void
    {
        // A fresh clone often has no loose ref file — the branch lives in
        // packed-refs. Both shapes are how real checkouts arrive.
        mkdir($this->root.'/git', 0755, true);
        file_put_contents($this->root.'/git/HEAD', "ref: refs/heads/main\n");
        file_put_contents(
            $this->root.'/git/packed-refs',
            "# pack-refs with: peeled fully-peeled sorted\ncccccccccccccccccccccccccccccccccccccccc refs/heads/main\n",
        );

        $this->assertSame(
            'cccccccccccccccccccccccccccccccccccccccc',
            $this->installer()->headCommit(),
        );
    }

    #[Test]
    public function not_a_git_checkout_means_the_sha_is_a_record_not_a_constraint(): void
    {
        // A cPanel upload has no .git. The operator's --sha is then what gets
        // recorded, because there is nothing to check it against.
        $installed = $this->installer()->install(
            $this->buildDirectory(['assets/app.js' => 'js']),
            'deadbeef',
        );

        $this->assertSame('deadbeef', $installed['sha']);
    }

    // ------------------------------------------------------------ command

    #[Test]
    public function the_command_reports_the_refusal_and_fails(): void
    {
        $this->swapInstaller();

        $bare = $this->root.'/bare';
        mkdir($bare, 0755, true);

        $this->artisan('sanitube:frontend:install', ['source' => $bare])
            ->expectsOutputToContain('manifest')
            ->assertFailed();
    }

    #[Test]
    public function the_command_warns_when_no_sha_ties_the_build_to_the_code(): void
    {
        $this->swapInstaller();

        $this->artisan('sanitube:frontend:install', [
            'source' => $this->buildDirectory(['assets/app.js' => 'js']),
        ])
            ->expectsOutputToContain('cannot be checked against this checkout')
            ->assertSuccessful();
    }

    // ----------------------------------------------------------- fixtures

    private function installer(): FrontendBuildInstaller
    {
        return new FrontendBuildInstaller(
            publicPath: $this->root.'/public',
            statePath: $this->root.'/state',
            gitPath: $this->root.'/git',
        );
    }

    private function swapInstaller(): void
    {
        $this->app->bind(FrontendBuildInstaller::class, fn (): FrontendBuildInstaller => $this->installer());
    }

    /**
     * @param  array<string, string>  $assets
     */
    private function buildDirectory(array $assets, ?string $manifest = null): string
    {
        $source = $this->root.'/source-'.uniqid();
        mkdir($source, 0755, true);

        $entries = [];

        foreach ($assets as $path => $content) {
            $directory = dirname($source.'/'.$path);

            if (! is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            file_put_contents($source.'/'.$path, $content);
            $entries['resources/js/'.basename($path)] = ['file' => $path];
        }

        file_put_contents($source.'/manifest.json', $manifest ?? (string) json_encode($entries));

        return $source;
    }

    /**
     * @param  array<string, string>  $entries
     */
    private function buildZip(array $entries): string
    {
        $path = $this->root.'/artifact-'.uniqid().'.zip';

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);

        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }

        $zip->close();

        return $path;
    }

    /**
     * @return list<string>
     */
    private function publicEntries(): array
    {
        $entries = array_values(array_diff(scandir($this->root.'/public') ?: [], ['.', '..']));
        sort($entries);

        return $entries;
    }

    private function fakeGitAt(string $sha): void
    {
        mkdir($this->root.'/git/refs/heads', 0755, true);
        file_put_contents($this->root.'/git/HEAD', "ref: refs/heads/main\n");
        file_put_contents($this->root.'/git/refs/heads/main', $sha."\n");
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path.'/'.$entry;

            if (is_dir($child) && ! is_link($child)) {
                $this->removeTree($child);
            } else {
                @unlink($child);
            }
        }

        @rmdir($path);
    }
}
