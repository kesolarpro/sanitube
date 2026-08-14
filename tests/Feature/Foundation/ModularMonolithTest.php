<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use Illuminate\Contracts\Console\Kernel;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Foundation\Modules\ModuleRegistry;
use SaniTube\Localization\LocaleRegistry;
use SaniTube\Observability\Capabilities\CapabilityRegistry;
use SaniTube\Storage\Contracts\StorageProvider;
use SaniTube\Storage\StorageManager;
use Tests\TestCase;

final class ModularMonolithTest extends TestCase
{
    #[Test]
    public function the_module_registry_is_bound_and_matches_the_configuration(): void
    {
        $registry = $this->app->make(ModuleRegistry::class);

        $this->assertSame(config('sanitube.modules'), $registry->names());
    }

    #[Test]
    public function every_module_directory_on_disk_is_declared_in_configuration(): void
    {
        // Guards against a module being created without being registered, which
        // would silently skip its routes, migrations and translations.
        $registry = $this->app->make(ModuleRegistry::class);
        $declared = $registry->names();

        $onDisk = array_map(
            'basename',
            array_filter((array) glob(base_path('src').'/*'), 'is_dir'),
        );

        // Foundation is the shared kernel, not a bounded context.
        $undeclared = array_values(array_diff($onDisk, $declared, ['Foundation']));

        $this->assertSame([], $undeclared, 'Undeclared modules: '.implode(', ', $undeclared));
    }

    #[Test]
    public function module_service_providers_are_registered(): void
    {
        $this->assertTrue($this->app->bound(LocaleRegistry::class));
        $this->assertTrue($this->app->bound(StorageManager::class));
        $this->assertTrue($this->app->bound(CapabilityRegistry::class));
    }

    #[Test]
    public function the_default_storage_provider_is_resolvable_through_the_contract(): void
    {
        $this->assertInstanceOf(StorageProvider::class, $this->app->make(StorageProvider::class));
    }

    #[Test]
    public function module_routes_are_loaded_under_the_configured_api_prefix(): void
    {
        $this->assertTrue($this->app['router']->has('api.v1.health.live'));
        $this->assertSame(
            'api/v1/health/live',
            $this->app['router']->getRoutes()->getByName('api.v1.health.live')->uri(),
        );
    }

    #[Test]
    public function the_health_command_is_registered(): void
    {
        $this->assertArrayHasKey('sanitube:health', $this->app[Kernel::class]->all());
    }
}
