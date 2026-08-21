<?php

declare(strict_types=1);

namespace SaniTube\Worker;

use SaniTube\Worker\Enums\WorkerCapability;

/**
 * Who a worker is and what it can do, as Core sees it.
 *
 * The whole handshake. Cheap enough to poll, and honest enough that Core never
 * has to assume: capabilities are what this worker *reports*, not what its
 * configuration implies.
 *
 * **The name is configured, never derived.** A hostname changes when a machine
 * is rebuilt and an IP changes when it moves; an audit line saying which worker
 * did something has to survive both. It is also what appears in a log, so it is
 * a label an operator chose and never a token or a fingerprint of one.
 */
final readonly class WorkerIdentity
{
    /**
     * @param  list<WorkerCapability>  $capabilities  what it can do right now
     * @param  list<WorkerCapability>  $registered  what it knows how to do at all
     * @param  array<string, string>  $tools
     */
    public function __construct(
        public string $name,
        public string $version,
        public array $capabilities,
        public array $registered = [],
        public array $tools = [],
        public int $protocol = WorkerProtocol::VERSION,
    ) {}

    /**
     * Whether this worker and this Core agree on the wire. A mismatch is a
     * reason to refuse jobs, never to reinterpret them: a payload read under
     * the wrong protocol does not fail, it does the wrong thing quietly.
     */
    public function speaksOurProtocol(): bool
    {
        return $this->protocol === WorkerProtocol::VERSION;
    }

    public function can(WorkerCapability $capability): bool
    {
        return in_array($capability, $this->capabilities, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'version' => $this->version,
            'protocol' => $this->protocol,
            'capabilities' => array_map(static fn (WorkerCapability $c): string => $c->value, $this->capabilities),
            'registered' => array_map(static fn (WorkerCapability $c): string => $c->value, $this->registered),
            'tools' => $this->tools,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): ?self
    {
        $name = $payload['name'] ?? null;

        if (! is_string($name) || trim($name) === '') {
            // A worker that will not say who it is has not completed the
            // handshake. Treated as no worker at all rather than as an
            // anonymous one.
            return null;
        }

        return new self(
            name: trim($name),
            version: is_string($payload['version'] ?? null) ? $payload['version'] : 'unknown',
            capabilities: self::capabilities($payload['capabilities'] ?? null),
            registered: self::capabilities($payload['registered'] ?? null),
            tools: self::tools($payload['tools'] ?? null),
            // Workers that predate the announcement spoke what is now called
            // version 1; absent is 1, not unknown. See WorkerProtocol.
            protocol: is_int($payload['protocol'] ?? null) ? $payload['protocol'] : WorkerProtocol::VERSION,
        );
    }

    /**
     * @return list<WorkerCapability>
     */
    private static function capabilities(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $capabilities = [];

        foreach ($value as $name) {
            $capability = is_string($name) ? WorkerCapability::tryFrom($name) : null;

            // A capability this Core does not know is dropped rather than
            // rejected: a newer worker talking to an older Core should be
            // usable for everything they have in common.
            if ($capability instanceof WorkerCapability && ! in_array($capability, $capabilities, true)) {
                $capabilities[] = $capability;
            }
        }

        return $capabilities;
    }

    /**
     * @return array<string, string>
     */
    private static function tools(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $tools = [];

        foreach ($value as $name => $version) {
            if (is_string($name) && is_string($version) && trim($version) !== '') {
                $tools[$name] = mb_substr($version, 0, 200);
            }
        }

        return $tools;
    }
}
