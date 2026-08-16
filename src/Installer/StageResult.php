<?php

declare(strict_types=1);

namespace SaniTube\Installer;

/**
 * What one stage of the installer did.
 *
 * Stages report rather than throw, so that an install which fails at stage six
 * says which stage that was and leaves the first five in place. An installer
 * that dies with a stack trace tells an operator on shared hosting nothing
 * they can act on, and one that rolls back everything makes a second attempt
 * start from nothing.
 *
 * `skipped` is a first-class outcome and not a quiet success: "the key already
 * existed so I left it alone" is exactly the fact somebody re-running the
 * installer needs to see.
 */
final readonly class StageResult
{
    private function __construct(
        public string $stage,
        public string $outcome,
        public string $detail,
    ) {}

    public const DONE = 'DONE';

    public const SKIPPED = 'SKIPPED';

    public const FAILED = 'FAILED';

    public static function done(string $stage, string $detail = ''): self
    {
        return new self($stage, self::DONE, $detail);
    }

    public static function skipped(string $stage, string $detail): self
    {
        return new self($stage, self::SKIPPED, $detail);
    }

    public static function failed(string $stage, string $detail): self
    {
        return new self($stage, self::FAILED, $detail);
    }

    public function hasFailed(): bool
    {
        return $this->outcome === self::FAILED;
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return ['stage' => $this->stage, 'outcome' => $this->outcome, 'detail' => $this->detail];
    }
}
