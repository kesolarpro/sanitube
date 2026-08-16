<?php

declare(strict_types=1);

namespace SaniTube\Deployment;

use Illuminate\Database\Connection;
use Illuminate\Support\ServiceProvider;
use SaniTube\Deployment\Console\BackupCommand;
use SaniTube\Deployment\Console\DoctorCommand;
use SaniTube\Deployment\Console\RestoreCommand;
use SaniTube\Deployment\Services\DatabaseDumper;

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
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([BackupCommand::class, RestoreCommand::class, DoctorCommand::class]);
        }
    }
}
