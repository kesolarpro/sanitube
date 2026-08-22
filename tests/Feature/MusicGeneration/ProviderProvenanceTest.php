<?php

declare(strict_types=1);

namespace Tests\Feature\MusicGeneration;

use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SaniTube\MusicGeneration\MusicGenerationManager;
use Tests\TestCase;

/**
 * ADR-0018 — no provider adapter without a published contract.
 *
 * GEN-005 researched Suno and found no public API: no console, no
 * documentation, no published request or response shapes. Every commercial
 * result for "Suno API" is a third party that reverse-engineers Suno's web
 * client and resells access.
 *
 * The standing constraint forbids integrating those, and the constraint is
 * right on its own merits: such a wrapper works by holding a supplier account's
 * session credentials, and that account is what holds the commercial rights to
 * everything made through it.
 *
 * **A rule in a document is not a rule.** This is the executable version, so
 * that a future session adding a dependency in good faith is stopped by CI
 * rather than by whether anybody remembered to read `docs/adr`.
 */
final class ProviderProvenanceTest extends TestCase
{
    /**
     * Hosts belonging to the reverse-engineering resellers, and the shape of a
     * private client endpoint. Matched against code with comments stripped, so
     * that documents and docblocks may discuss them freely — which they must,
     * since explaining why something is excluded requires naming it.
     *
     * @var list<string>
     */
    private const FORBIDDEN_HOSTS = [
        'studio-api.suno.ai',
        'studio-api.prod.suno.com',
        'suno.com/api',
        'sunoapi',
        'suno-api',
        'apibox.erweima',
        'gptproto',
        'musicapi.ai',
        'aimusicapi',
    ];

    #[Test]
    public function no_reverse_engineered_provider_endpoint_is_referenced_in_code(): void
    {
        foreach ($this->sourceFiles() as $file) {
            $code = strtolower($this->codeWithoutComments($file));

            foreach (self::FORBIDDEN_HOSTS as $host) {
                $this->assertStringNotContainsString(
                    $host,
                    $code,
                    $file.' references '.$host.'. See ADR-0018: an adapter is written only '
                        .'against a contract its supplier has published.',
                );
            }
        }
    }

    #[Test]
    public function no_dependency_claims_to_wrap_a_generation_provider(): void
    {
        /** @var array<string, mixed> $composer */
        $composer = (array) json_decode((string) file_get_contents(base_path('composer.json')), true);

        $packages = array_merge(
            array_keys((array) ($composer['require'] ?? [])),
            array_keys((array) ($composer['require-dev'] ?? [])),
        );

        foreach ($packages as $package) {
            $name = strtolower((string) $package);

            foreach (['suno', 'udio', 'musicapi'] as $vendor) {
                $this->assertStringNotContainsString(
                    $vendor,
                    $name,
                    'Dependency ['.$name.'] wraps a generation provider. See ADR-0018.',
                );
            }
        }
    }

    #[Test]
    public function the_platform_ships_with_no_generation_provider_at_all(): void
    {
        // The environment is *removed* before the file is read, and that is the
        // whole point of this test rather than a detail of it.
        //
        // Requiring the file alone proved nothing: this machine's .env and the
        // shipped .env.example both set SANITUBE_GENERATION_PROVIDER=none, so
        // the assertion passed for a default of 'fake' too. It was reporting on
        // the environment it ran in, which every environment satisfies, instead
        // of on the code. Found by mutation; it would never have failed.
        $shipped = $this->shippedConfiguration();

        $this->assertSame(MusicGenerationManager::DISABLED, $shipped['default']);

        /** @var array<string, mixed> $providers */
        $providers = (array) $shipped['providers'];

        // `fake` is shipped deliberately -- it is what makes the Studio
        // demonstrable and fully exercised without a vendor, which is what
        // makes ADR-0018 affordable rather than merely principled.
        //
        // CFG-002 added `acestep`, and it is the kind of provider this ADR
        // exists to *allow*: ACE-Step is self-hosted on hardware the operator
        // owns, reached over its own published contract through a SaniTube
        // worker, holding nobody's session credentials and giving nobody else
        // the rights to what it makes. Its adapter was already in this
        // repository and already passed the provenance checks below; what was
        // missing was the declaration, so the one real engine SaniTube has
        // could not be selected. The shipped *default* is still `none`.
        //
        // A fourth name here still has to be a deliberate act. A vendor's
        // would fail the checks that follow, not this line.
        $this->assertSame(['none', 'fake', 'acestep'], array_keys($providers));
    }

    #[Test]
    public function every_shipped_provider_is_one_this_repository_wrote(): void
    {
        $adapters = glob(base_path('src/MusicGeneration/Providers/*.php')) ?: [];

        $names = array_map(
            static fn (string $path): string => basename($path, '.php'),
            $adapters,
        );

        sort($names);

        // A new file here is the thing this test exists to notice. Adding a
        // real adapter is allowed -- it just has to be a deliberate act that
        // updates this list, and at that point ADR-0018's four conditions are
        // the question being answered.
        //
        // **GEN-008 added `SelfHostedAceStepProvider`, and here are the four
        // answers**, so that a reader of this list does not have to go looking:
        //
        //   1. *Published by its supplier?* Yes. `infer-api.py` in ACE-Step's
        //      own repository, read from `raw.githubusercontent.com`, md5
        //      `783d768bdb8f32255cd6e4ac65ed7e67`, re-read at the start of both
        //      GEN-007 and GEN-008 and unchanged.
        //   2. *Read from a primary source?* Yes -- the project's repository,
        //      not a mirror, not a blog, and not a reseller.
        //   3. *No reverse engineering?* Nothing here was inferred from
        //      traffic. Every field sent is a field that file declares, and the
        //      capabilities this adapter claims are only those it documents:
        //      no stems, no extend, no remix, no cancel, no webhook.
        //   4. *Commercially usable?* The **code** is Apache 2.0. The model
        //      weights' licence could not be read from this environment, so the
        //      commercial-rights status of anything it produces stays UNKNOWN
        //      -- which is the platform's default and is never inferred from
        //      the fact of a generation.
        //
        // `StorageGeneratedAudioReader` is not an adapter to anybody: it opens
        // a key in this installation's own storage.
        $this->assertSame([
            'FakeMusicGenerationProvider',
            'HttpGeneratedAudioReader',
            'SelfHostedAceStepProvider',
            'StorageGeneratedAudioReader',
            'UnavailableMusicGenerationProvider',
        ], $names);
    }

    /**
     * `config/generation.php` as it would read on a machine that has set
     * nothing — which is what "ships with" means.
     *
     * @return array<string, mixed>
     */
    private function shippedConfiguration(): array
    {
        $key = 'SANITUBE_GENERATION_PROVIDER';
        $environment = $_ENV[$key] ?? null;
        $server = $_SERVER[$key] ?? null;
        $process = getenv($key);

        unset($_ENV[$key], $_SERVER[$key]);
        putenv($key);

        try {
            /** @var array<string, mixed> $shipped */
            $shipped = require base_path('config/generation.php');

            return $shipped;
        } finally {
            // Restored whatever happened, or every later test in the process
            // runs against an environment this one quietly emptied.
            if ($environment !== null) {
                $_ENV[$key] = $environment;
            }

            if ($server !== null) {
                $_SERVER[$key] = $server;
            }

            if (is_string($process)) {
                putenv($key.'='.$process);
            }
        }
    }

    /**
     * Every PHP file under `src/` and `config/`.
     *
     * Walked recursively rather than globbed. A glob pattern with a doubled
     * wildcard in the middle looks exhaustive and is not — PHP's is an ordinary
     * single wildcard, so such a pattern matches only files at exactly one
     * depth and silently skips module roots like
     * `src/MusicGeneration/ProviderFailure.php`. When GEN-005 found it, it was
     * scanning 368 of the repository's 634 source files and reporting a pass.
     * A guardrail with a hole in its scan is worse than none.
     *
     * @return list<string>
     */
    private function sourceFiles(): array
    {
        $files = [];

        foreach ([base_path('src'), base_path('config')] as $root) {
            /** @var iterable<string, \SplFileInfo> $iterator */
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);

        // Counted, not merely non-empty: "some files" is what a broken pattern
        // also returns.
        $this->assertGreaterThan(400, count($files), 'The scan is missing files it should have walked.');

        return $files;
    }

    private function codeWithoutComments(string $file): string
    {
        $code = '';

        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }
}
