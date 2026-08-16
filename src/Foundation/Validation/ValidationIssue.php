<?php

declare(strict_types=1);

namespace SaniTube\Foundation\Validation;

/**
 * One thing wrong with a release, said in a way an interface can translate.
 *
 * REL-002. Before this, validation answered in English sentences, and those
 * sentences became a contract by accident: the interface rendered them
 * verbatim to a French label, and `SubmitDelivery` decided whether a
 * distributor had been unreachable by searching one for a substring. Both are
 * the same mistake — a message written for a developer reading a log being
 * used as data.
 *
 * A code is data. A message is for logs.
 *
 * Four fields, each earning its place:
 *
 *   - **`code`** — what is wrong, as a stable identifier. This is the contract.
 *   - **`severity`** — whether it blocks. A release with no cover cannot be
 *     delivered; one with no catalogue number is merely incomplete, and a
 *     validator that reported both the same way trains its reader to ignore
 *     the list.
 *   - **`path`** — where. `cover`, `artists`, `tracks.3`. It is what lets an
 *     interface put the message beside the field rather than in a pile at the
 *     bottom.
 *   - **`context`** — the values the translation needs, never a pre-formatted
 *     sentence. `['number' => 4, 'disc' => 1]`, so every locale can put them in
 *     its own word order.
 *
 * `message()` still exists, and is still English. It is what goes into a log,
 * an exception and a delivery-attempt record. What changed is that nothing
 * *decides* anything from it.
 */
final readonly class ValidationIssue
{
    public const ERROR = 'ERROR';

    public const WARNING = 'WARNING';

    /**
     * @param  array<string, scalar|null>  $context
     */
    private function __construct(
        public string $code,
        public string $severity,
        public string $path,
        public array $context,
        private string $message,
    ) {}

    /**
     * @param  array<string, scalar|null>  $context
     */
    public static function error(string $code, string $path, string $message, array $context = []): self
    {
        return new self($code, self::ERROR, $path, $context, $message);
    }

    /**
     * @param  array<string, scalar|null>  $context
     */
    public static function warning(string $code, string $path, string $message, array $context = []): self
    {
        return new self($code, self::WARNING, $path, $context, $message);
    }

    public function isError(): bool
    {
        return $this->severity === self::ERROR;
    }

    /**
     * The English sentence, for a developer reading a log.
     *
     * Deliberately not exposed as a public property. It is available where it
     * is useful and awkward enough to reach for that nobody matches on it by
     * accident — which is exactly how the old contract formed.
     */
    public function message(): string
    {
        return $this->message;
    }

    /**
     * The identity of an issue, for deduplication.
     *
     * Code and path together, not the message. Two tracks missing a genre are
     * two issues; the same track reported twice by two validators is one.
     */
    public function key(): string
    {
        return $this->code.'@'.$this->path;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'severity' => $this->severity,
            'path' => $this->path,
            'context' => $this->context,
        ];
    }
}
