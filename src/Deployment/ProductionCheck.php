<?php

declare(strict_types=1);

namespace SaniTube\Deployment;

use SaniTube\Observability\Capabilities\Capability;

/**
 * One thing that is either safe to run in production or is not.
 *
 * Deliberately not a {@see Capability}.
 * That answers "what can this server do" — FFmpeg, Redis, object storage — and
 * is the same question in development as in production. This answers a
 * different one: **is this installation configured the way a live one has to
 * be**, and almost every check here passes on a developer's machine while
 * being a serious problem on a public host. `APP_DEBUG=true` is correct
 * locally and leaks stack traces, environment variables and database
 * credentials to anyone who can trigger an error in production.
 *
 * So the two commands stay separate: `sanitube:health` reports the machine,
 * `sanitube:doctor` reports the installation. The doctor reads the health
 * report rather than re-deriving it.
 *
 * Four verdicts, and the difference between the last two is the whole reason
 * the type exists:
 *
 *   - **`READY`** — checked, and correct.
 *   - **`BLOCKER`** — checked, and wrong. Exit code non-zero.
 *   - **`WARNING`** — checked, defensible, worth a second look. A queue on the
 *     database driver works; it is simply slower than it needs to be.
 *   - **`UNKNOWN`** — *could not be checked*. Never counted as ready. An
 *     installation whose database cannot be reached does not have a healthy
 *     schema; it has no answer, and reporting no answer as a pass is how a
 *     dashboard reassures somebody about a server that is already down.
 */
final readonly class ProductionCheck
{
    public const READY = 'READY';

    public const BLOCKER = 'BLOCKER';

    public const WARNING = 'WARNING';

    public const UNKNOWN = 'UNKNOWN';

    private function __construct(
        public string $section,
        public string $key,
        public string $verdict,
        public string $summary,
        public ?string $remediation = null,
    ) {}

    public static function ready(string $section, string $key, string $summary): self
    {
        return new self($section, $key, self::READY, $summary);
    }

    public static function blocker(string $section, string $key, string $summary, string $remediation): self
    {
        return new self($section, $key, self::BLOCKER, $summary, $remediation);
    }

    public static function warning(string $section, string $key, string $summary, ?string $remediation = null): self
    {
        return new self($section, $key, self::WARNING, $summary, $remediation);
    }

    public static function unknown(string $section, string $key, string $summary, ?string $remediation = null): self
    {
        return new self($section, $key, self::UNKNOWN, $summary, $remediation);
    }

    public function isBlocking(): bool
    {
        return $this->verdict === self::BLOCKER;
    }

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'section' => $this->section,
            'key' => $this->key,
            'verdict' => $this->verdict,
            'summary' => $this->summary,
            'remediation' => $this->remediation,
        ];
    }
}
