<?php

declare(strict_types=1);

namespace SaniTube\Deployment;

use Illuminate\Database\Connection;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\ServiceProvider;
use SaniTube\Deployment\Console\BackupCommand;
use SaniTube\Deployment\Console\DoctorCommand;
use SaniTube\Deployment\Console\FrontendInstallCommand;
use SaniTube\Deployment\Console\HostCommand;
use SaniTube\Deployment\Console\ProvisionCommand;
use SaniTube\Deployment\Console\RestoreCommand;
use SaniTube\Deployment\Console\SelfTestCommand;
use SaniTube\Deployment\Console\SmokeCommand;
use SaniTube\Deployment\Frontend\FrontendBuildInstaller;
use SaniTube\Deployment\Host\HostProbe;
use SaniTube\Deployment\Host\RealHostProbe;
use SaniTube\Deployment\Services\DatabaseDumper;
use SaniTube\Deployment\Smoke\SmokeTestRunner;

final class DeploymentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bound to the concrete Connection rather than the interface: the
        // dumper needs the schema builder, which only the class exposes.
        $this->app->bind(DatabaseDumper::class, fn (): DatabaseDumper => new DatabaseDumper(
            $this->app->make('db')->connection(),
        ));

        $this->app->bind(Connection::class, fn (): Connection => $this->app->make('db')->connection());

        // The seam host inspection is tested through: production asks the real
        // machine, a test binds a fake and asks anything it likes.
        $this->app->bind(HostProbe::class, RealHostProbe::class);

        $this->app->bind(FrontendBuildInstaller::class, fn (): FrontendBuildInstaller => new FrontendBuildInstaller(
            publicPath: public_path(),
            statePath: storage_path('framework'),
            gitPath: base_path('.git'),
        ));

        $this->app->bind(SmokeTestRunner::class, fn (): SmokeTestRunner => new SmokeTestRunner(
            http: $this->app->make(Factory::class),
            publicPath: public_path(),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([BackupCommand::class, RestoreCommand::class, DoctorCommand::class, HostCommand::class, ProvisionCommand::class, FrontendInstallCommand::class, SmokeCommand::class, SelfTestCommand::class]);
        }
    }
}
