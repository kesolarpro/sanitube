<?php

declare(strict_types=1);

namespace SaniTube\Deployment\Smoke;

/**
 * One HTTP fact about a deployed installation, with its verdict.
 */
final readonly class SmokeCheck
{
    public const PASSED = 'PASSED';

    public const FAILED = 'FAILED';

    public const SKIPPED = 'SKIPPED';

    private function __construct(
        public string $name,
        public string $outcome,
        public string $detail,
    ) {}

    public static function passed(string $name, string $detail): self
    {
        return new self($name, self::PASSED, $detail);
    }

    public static function failed(string $name, string $detail): self
    {
        return new self($name, self::FAILED, $detail);
    }

    public static function skipped(string $name, string $detail): self
    {
        return new self($name, self::SKIPPED, $detail);
    }

    public function hasFailed(): bool
    {
        return $this->outcome === self::FAILED;
    }

    /**
     * @return array{name: string, outcome: string, detail: string}
     */
    public function toArray(): array
    {
        return ['name' => $this->name, 'outcome' => $this->outcome, 'detail' => $this->detail];
    }
}
