<?php

declare(strict_types=1);

namespace SaniTube\Observability\Capabilities\Detectors;

use Illuminate\Database\DatabaseManager;
use SaniTube\Observability\Capabilities\Capability;
use SaniTube\Observability\Capabilities\CapabilityDetector;
use Throwable;

final readonly class DatabaseDetector implements CapabilityDetector
{
    public function __construct(private DatabaseManager $database) {}

    public function detect(): Capability
    {
        $connection = $this->database->connection();
        $driver = $connection->getDriverName();

        try {
            $connection->getPdo();
        } catch (Throwable $exception) {
            return Capability::unavailable(
                key: 'database',
                label: 'Database',
                detail: sprintf('Cannot connect using the [%s] driver: %s', $driver, $exception->getMessage()),
                remediation: 'Check DB_HOST, DB_DATABASE, DB_USERNAME and DB_PASSWORD in .env, '
                    .'and that the database user may connect from this host.',
            );
        }

        // MySQL and MariaDB are the supported production engines. SQLite is
        // fine for local work and tests; PostgreSQL is not exercised by the
        // migrations, so it is reported rather than silently accepted.
        return match ($driver) {
            'mysql', 'mariadb' => Capability::available('database', 'Database', sprintf('Connected (%s).', $driver)),
            'sqlite' => Capability::degraded(
                key: 'database',
                label: 'Database',
                detail: 'Connected to SQLite.',
                remediation: 'SQLite is intended for local development and tests. Use MySQL or MariaDB in production.',
            ),
            default => Capability::degraded(
                key: 'database',
                label: 'Database',
                detail: sprintf('Connected using the untested [%s] driver.', $driver),
                remediation: 'SaniTube targets MySQL/MariaDB. Other engines are not covered by the migration suite.',
            ),
        };
    }
}
