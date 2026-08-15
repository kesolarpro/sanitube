<?php

declare(strict_types=1);

namespace SaniTube\Ingestion\Manifest;

/**
 * A parsed manifest: the rows that will be imported, and the ones that will
 * not.
 *
 * A manifest is a **reference list with evidence attached**, which is why it
 * is accepted in the same position as an explicit list of references and never
 * alongside one. Two things naming what to import, disagreeing, with no rule
 * for which wins, is how an import quietly does something nobody asked for.
 *
 * Rows are keyed by reference. That is not merely convenient: a reference can
 * appear at most once, because two lines describing the same object with
 * different titles are not a merge problem, they are a question for the person
 * who wrote the manifest. Both lines are refused rather than one being picked.
 */
final readonly class Manifest
{
    /**
     * @param  array<string, ManifestRow>  $rows  keyed by reference
     * @param  list<ManifestRowError>  $errors
     * @param  list<string>  $unknownColumns
     */
    public function __construct(
        public array $rows = [],
        public array $errors = [],
        public array $unknownColumns = [],
    ) {}

    /**
     * Every reference this manifest asks to be imported, in file order.
     *
     * @return list<string>
     */
    public function references(): array
    {
        return array_keys($this->rows);
    }

    public function rowFor(string $reference): ?ManifestRow
    {
        return $this->rows[$reference] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }

    public function acceptedCount(): int
    {
        return count($this->rows);
    }

    public function refusedCount(): int
    {
        return count($this->errors);
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            'accepted' => $this->acceptedCount(),
            'refused' => $this->refusedCount(),
            'errors' => array_map(
                static fn (ManifestRowError $error): array => $error->toArray(),
                $this->errors,
            ),
            'unknown_columns' => $this->unknownColumns,
        ];
    }
}
