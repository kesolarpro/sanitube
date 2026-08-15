<?php

declare(strict_types=1);

namespace SaniTube\Storage;

/**
 * Removes configured secrets from text on its way to a human.
 *
 * Object storage SDKs put a great deal into their exception messages, and the
 * requests they describe are signed with the very credentials that must never
 * be printed. A storage diagnostic is precisely where a wrong secret shows up,
 * and precisely the output someone pastes into a support thread.
 *
 * The approach is narrow on purpose: it masks the values this installation is
 * actually configured with, rather than guessing at what a secret looks like.
 * Nothing here is a substitute for keeping secrets out of Git — it is the last
 * barrier, not the first.
 */
final readonly class CredentialRedactor
{
    public const MASK = '[redacted]';

    /**
     * Below this length a "secret" is more likely to be a common word than a
     * credential, and masking it would corrupt the message it appears in.
     */
    private const MINIMUM_LENGTH = 8;

    /** @var list<string> */
    private array $secrets;

    /**
     * @param  array<int|string, mixed>  $secrets
     */
    public function __construct(array $secrets)
    {
        $usable = [];

        foreach ($secrets as $secret) {
            if (is_string($secret) && strlen($secret) >= self::MINIMUM_LENGTH) {
                $usable[$secret] = true;
            }
        }

        // Longest first, so a secret that contains another is masked whole.
        $values = array_keys($usable);
        usort($values, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        $this->secrets = $values;
    }

    /**
     * Builds a redactor from every credential the filesystem disks declare.
     */
    public static function fromDiskConfiguration(): self
    {
        $secrets = [];

        /** @var array<string, mixed> $disks */
        $disks = (array) config('filesystems.disks', []);

        foreach ($disks as $disk) {
            if (! is_array($disk)) {
                continue;
            }

            foreach (['key', 'secret', 'token', 'password'] as $field) {
                $secrets[] = $disk[$field] ?? null;
            }
        }

        return new self($secrets);
    }

    public function redact(?string $text): ?string
    {
        if ($text === null || $this->secrets === []) {
            return $text;
        }

        return str_replace($this->secrets, self::MASK, $text);
    }
}
