<?php

declare(strict_types=1);

namespace Tests\Unit\Foundation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SaniTube\Foundation\Modules\Exceptions\UnknownModuleException;
use SaniTube\Foundation\Modules\ModuleRegistry;

final class ModuleRegistryTest extends TestCase
{
    #[Test]
    public function it_builds_a_module_per_configured_name(): void
    {
        $registry = new ModuleRegistry(['Catalog', 'Distribution'], '/app/src');

        $this->assertCount(2, $registry);
        $this->assertSame(['Catalog', 'Distribution'], $registry->names());
    }

    #[Test]
    public function it_derives_namespace_path_and_provider_from_the_module_name(): void
    {
        $registry = new ModuleRegistry(['MusicGeneration'], '/app/src');

        $module = $registry->get('MusicGeneration');

        $this->assertSame('SaniTube\MusicGeneration', $module->namespace);
        $this->assertSame('/app/src/MusicGeneration', $module->path);
        $this->assertSame('/app/src/MusicGeneration/Routes/api.php', $module->path('Routes/api.php'));
        $this->assertSame(
            'SaniTube\MusicGeneration\MusicGenerationServiceProvider',
            $module->providerClass(),
        );
    }

    #[Test]
    public function multi_word_modules_get_a_stable_lookup_key(): void
    {
        $registry = new ModuleRegistry(['MusicGeneration'], '/app/src');

        $this->assertSame('music-generation', $registry->get('MusicGeneration')->key());
        $this->assertTrue($registry->has('music-generation'));
        $this->assertTrue($registry->has('MusicGeneration'));
    }

    #[Test]
    public function a_trailing_slash_on_the_base_path_does_not_produce_a_double_slash(): void
    {
        $registry = new ModuleRegistry(['Catalog'], '/app/src/');

        $this->assertSame('/app/src/Catalog', $registry->get('Catalog')->path);
    }

    #[Test]
    public function it_rejects_an_unknown_module_and_names_the_registered_ones(): void
    {
        $registry = new ModuleRegistry(['Catalog'], '/app/src');

        $this->expectException(UnknownModuleException::class);
        $this->expectExceptionMessage('Registered modules: Catalog.');

        $registry->get('Royalties');
    }

    #[Test]
    public function installed_only_returns_modules_that_exist_on_disk(): void
    {
        // The real src/ directory. Royalties is declared in configuration but
        // has no code yet, which is the state every module starts in and must
        // cost nothing.
        $registry = new ModuleRegistry(['Localization', 'Royalties'], dirname(__DIR__, 3).'/src');

        $installed = array_map(static fn ($module): string => $module->name, $registry->installed());

        $this->assertContains('Localization', $installed);
        $this->assertNotContains('Royalties', $installed);
    }
}
