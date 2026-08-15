<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SaniTube\Catalog\Enums\ExternalIdentifierType;
use SplFileInfo;
use Tests\TestCase;

/**
 * The catalogue is the source of truth; a provider is a supplier.
 *
 * The way that guarantee is lost is never a decision — it is a column called
 * `spotify_url`, a status enum with a distributor's wording, a migration that
 * knows a vendor's name. Once the schema knows a provider, replacing that
 * provider stops being a configuration change.
 *
 * So the domain is checked for provider vocabulary mechanically. Vendor names
 * belong in *data* — an identifier's namespace, a config value, an adapter —
 * never in the shape of the catalogue.
 */
final class ProviderLeakageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Names that must not appear in the domain layer.
     *
     * @var list<string>
     */
    private const FORBIDDEN = [
        'toolost', 'too_lost', 'tunecore', 'suno', 'spotify',
        'apple_music', 'applemusic', 'openai', 'anthropic', 'claude',
    ];

    /**
     * Where the domain model and its schema live. The Distribution,
     * MusicGeneration and AI modules are deliberately excluded: those exist
     * precisely to name providers, behind contracts.
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
    public function no_provider_name_appears_in_the_domain(): void
    {
        $offenders = [];

        foreach ($this->domainFiles() as $file) {
            $contents = strtolower((string) file_get_contents($file->getPathname()));

            foreach (self::FORBIDDEN as $needle) {
                if (str_contains($contents, $needle)) {
                    $offenders[] = sprintf('%s contains [%s]', $this->relative($file), $needle);
                }
            }
        }

        $this->assertSame([], $offenders, 'Provider vocabulary leaked into the domain: '.implode('; ', $offenders));
    }

    #[Test]
    public function no_provider_name_appears_in_any_migration(): void
    {
        $offenders = [];

        foreach ($this->migrationFiles() as $file) {
            $contents = strtolower((string) file_get_contents($file->getPathname()));

            foreach (self::FORBIDDEN as $needle) {
                if (str_contains($contents, $needle)) {
                    $offenders[] = sprintf('%s contains [%s]', $this->relative($file), $needle);
                }
            }
        }

        $this->assertSame([], $offenders, 'Provider vocabulary leaked into the schema: '.implode('; ', $offenders));
    }

    #[Test]
    public function vendor_identifiers_are_expressed_as_data_not_as_schema(): void
    {
        // The mechanism that keeps providers out of the schema: a vendor's id
        // is a row whose namespace names the vendor, not a column named after
        // it.
        $this->assertTrue(Schema::hasColumn('external_identifiers', 'namespace'));

        foreach (ExternalIdentifierType::cases() as $type) {
            $lower = strtolower($type->value);

            foreach (self::FORBIDDEN as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $lower,
                    sprintf('Identifier type [%s] names a provider.', $type->value),
                );
            }
        }
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

    /**
     * @return list<SplFileInfo>
     */
    private function migrationFiles(): array
    {
        $files = [];

        foreach ((array) glob(base_path('database/migrations/*.php')) as $migration) {
            $files[] = new SplFileInfo((string) $migration);
        }

        foreach ((array) glob(base_path('src/*/Database/Migrations/*.php')) as $migration) {
            $files[] = new SplFileInfo((string) $migration);
        }

        return $files;
    }

    private function relative(SplFileInfo $file): string
    {
        return str_replace(base_path().'/', '', $file->getPathname());
    }
}
