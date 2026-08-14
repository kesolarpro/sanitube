<?php

declare(strict_types=1);

namespace SaniTube\Api\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Pagination\Cursor;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Shared validation for the read-only listing endpoints.
 *
 * Two decisions are deliberate and both are about refusing to guess:
 *
 * An unknown query parameter is a 422, not something to ignore. A caller who
 * writes `?stat=READY` and gets a silently unfiltered list will believe the
 * whole catalogue is READY; failing loudly costs one error response and saves
 * that class of misunderstanding entirely.
 *
 * `per_page` out of range is also a 422 rather than a silent clamp, for the
 * same reason: a caller asking for 500 rows and receiving 100 without being
 * told will page through the catalogue believing they have seen all of it.
 */
abstract class CatalogIndexRequest extends FormRequest
{
    public const DEFAULT_PER_PAGE = 25;

    public const MIN_PER_PAGE = 1;

    public const MAX_PER_PAGE = 100;

    /**
     * Filter names this endpoint understands, mapped to their allowed values.
     * A null value means "any string is syntactically acceptable".
     *
     * @return array<string, list<string>|null>
     */
    abstract protected function allowedFilters(): array;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'per_page' => ['sometimes', 'integer', 'min:'.self::MIN_PER_PAGE, 'max:'.self::MAX_PER_PAGE],

            // An undecodable cursor is rejected rather than treated as "no
            // cursor". Laravel's default is to fall back to the first page,
            // which would hand a caller who is mid-pagination the start of the
            // collection again while still answering 200 — a silent restart
            // that looks exactly like normal output.
            'cursor' => ['sometimes', 'string', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_string($value) || Cursor::fromEncoded($value) === null) {
                    $fail('The cursor is not valid. Use the cursor returned by a previous page.');
                }
            }],
        ];

        foreach ($this->allowedFilters() as $filter => $allowedValues) {
            $rules[$filter] = $allowedValues === null
                ? ['sometimes', 'string', 'max:191']
                : ['sometimes', Rule::in($allowedValues)];
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $known = array_merge(['per_page', 'cursor'], array_keys($this->allowedFilters()));
            $unknown = array_diff(array_keys($this->query()), $known);

            foreach ($unknown as $parameter) {
                $validator->errors()->add(
                    (string) $parameter,
                    sprintf(
                        'Unknown query parameter [%s]. Supported: %s.',
                        (string) $parameter,
                        implode(', ', $known),
                    ),
                );
            }
        });
    }

    public function perPage(): int
    {
        $perPage = $this->integer('per_page', self::DEFAULT_PER_PAGE);

        return $perPage === 0 ? self::DEFAULT_PER_PAGE : $perPage;
    }

    /**
     * @return array<string, string>
     */
    public function filters(): array
    {
        // A query parameter can arrive as an array (`?status[]=X`), so this is
        // genuinely mixed and the guard below is not decoration.
        /** @var array<string, mixed> $filters */
        $filters = $this->only(array_keys($this->allowedFilters()));

        return array_filter($filters, static fn (mixed $value): bool => is_string($value) && $value !== '');
    }
}
