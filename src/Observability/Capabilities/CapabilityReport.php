<?php

declare(strict_types=1);

namespace SaniTube\Observability\Capabilities;

use Countable;
use IteratorAggregate;
use Traversable;

/**
 * The outcome of running every registered detector.
 *
 * @implements IteratorAggregate<int, Capability>
 */
final readonly class CapabilityReport implements Countable, IteratorAggregate
{
    /**
     * @param  list<Capability>  $capabilities
     */
    public function __construct(private array $capabilities) {}

    /**
     * @return list<Capability>
     */
    public function all(): array
    {
        return $this->capabilities;
    }

    public function get(string $key): ?Capability
    {
        foreach ($this->capabilities as $capability) {
            if ($capability->key === $key) {
                return $capability;
            }
        }

        return null;
    }

    /**
     * Capabilities that are required and missing — the ones that must be fixed
     * before an install or a deployment can be considered successful.
     *
     * @return list<Capability>
     */
    public function blocking(): array
    {
        return array_values(array_filter(
            $this->capabilities,
            static fn (Capability $capability): bool => $capability->isBlocking(),
        ));
    }

    public function isHealthy(): bool
    {
        return $this->blocking() === [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_map(static fn (Capability $capability): array => $capability->toArray(), $this->capabilities);
    }

    public function count(): int
    {
        return count($this->capabilities);
    }

    public function getIterator(): Traversable
    {
        return new \ArrayIterator($this->capabilities);
    }
}
