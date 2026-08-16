<?php

declare(strict_types=1);

namespace SaniTube\Foundation\Validation;

/**
 * What is wrong with a release, separated into what blocks it and what does not.
 *
 * The separation is the point. A release with no cover cannot be delivered —
 * every store rejects it — while a release with no catalogue number is merely
 * incomplete, and a validator that reported both as "errors" would train its
 * reader to ignore the list.
 *
 * REL-002 changed what is inside: {@see ValidationIssue} objects rather
 * than English sentences. The severity now lives on the issue, so the two
 * lists are views over one set rather than two independently-built arrays that
 * can disagree about which is which.
 */
final readonly class ValidationResult
{
    /** @var list<ValidationIssue> */
    public array $issues;

    /**
     * @param  list<ValidationIssue>  $issues
     */
    public function __construct(array $issues = [])
    {
        // Deduplicated on (code, path). Two validators reporting the same
        // missing cover is one problem, and a list that says it twice is a
        // list somebody stops reading.
        $seen = [];
        $unique = [];

        foreach ($issues as $issue) {
            if (array_key_exists($issue->key(), $seen)) {
                continue;
            }

            $seen[$issue->key()] = true;
            $unique[] = $issue;
        }

        $this->issues = $unique;
    }

    /**
     * @return list<ValidationIssue>
     */
    public function errors(): array
    {
        return array_values(array_filter($this->issues, static fn (ValidationIssue $i): bool => $i->isError()));
    }

    /**
     * @return list<ValidationIssue>
     */
    public function warnings(): array
    {
        return array_values(array_filter($this->issues, static fn (ValidationIssue $i): bool => ! $i->isError()));
    }

    public function isValid(): bool
    {
        return $this->errors() === [];
    }

    public function has(string $code): bool
    {
        foreach ($this->issues as $issue) {
            if ($issue->code === $code) {
                return true;
            }
        }

        return false;
    }

    /**
     * The English sentences, for a log or a delivery-attempt record.
     *
     * A method rather than a property, and named for what it is. Nothing in
     * the platform decides anything from these — {@see has()} is how a caller
     * asks whether something happened.
     *
     * @return list<string>
     */
    public function messages(): array
    {
        return array_map(static fn (ValidationIssue $i): string => $i->message(), $this->issues);
    }

    public function with(self $other): self
    {
        return new self([...$this->issues, ...$other->issues]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'valid' => $this->isValid(),
            'errors' => array_map(
                static fn (ValidationIssue $i): array => $i->toArray(),
                $this->errors(),
            ),
            'warnings' => array_map(
                static fn (ValidationIssue $i): array => $i->toArray(),
                $this->warnings(),
            ),
        ];
    }
}
