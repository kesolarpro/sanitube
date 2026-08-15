<?php

declare(strict_types=1);

namespace SaniTube\Distribution;

use RuntimeException;
use SaniTube\Distribution\Contracts\Distributor;
use SaniTube\Distribution\Providers\NullDistributor;
use SaniTube\Distribution\Testing\FakeDistributor;

/**
 * Resolves configured distributors by name.
 *
 * Same shape and same disabled-name convention as the storage, AI and
 * generation managers, so an operator learns one rule rather than four.
 */
final class DistributorManager
{
    public const DISABLED = 'none';

    /** @var array<string, Distributor> */
    private array $resolved = [];

    /**
     * @param  array<string, array<string, mixed>>  $distributors
     */
    public function __construct(
        private readonly array $distributors,
        private readonly string $defaultDistributor,
    ) {}

    /**
     * Empty configuration means `none`; an unknown non-empty name is an error.
     */
    public static function normaliseName(mixed $configured): string
    {
        if (! is_string($configured)) {
            return self::DISABLED;
        }

        $trimmed = trim($configured);

        return $trimmed === '' || strtolower($trimmed) === 'null' ? self::DISABLED : $trimmed;
    }

    public function default(): Distributor
    {
        return $this->distributor($this->defaultDistributor);
    }

    public function defaultName(): string
    {
        return $this->defaultDistributor;
    }

    public function distributor(string $name): Distributor
    {
        return $this->resolved[$name] ??= $this->resolve($name);
    }

    public function register(string $name, Distributor $distributor): void
    {
        $this->resolved[$name] = $distributor;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_values(array_unique([...array_keys($this->distributors), ...array_keys($this->resolved)]));
    }

    private function resolve(string $name): Distributor
    {
        $configuration = $name === '' ? null : ($this->distributors[$name] ?? null);

        if (! is_array($configuration)) {
            throw new RuntimeException(sprintf(
                'No distributor named [%s] is configured. Known: %s.',
                $name,
                implode(', ', array_keys($this->distributors)) ?: 'none',
            ));
        }

        $driver = is_string($configuration['driver'] ?? null) ? $configuration['driver'] : $name;

        return match ($driver) {
            'fake' => new FakeDistributor($name, (bool) ($configuration['sandbox'] ?? true)),
            'none' => new NullDistributor,
            default => throw new RuntimeException(sprintf('No adapter implements the [%s] driver.', $driver)),
        };
    }
}
