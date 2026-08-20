<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Requests\Deduplication;

use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use SaniTube\Deduplication\Enums\DuplicateDecision;
use SaniTube\Deduplication\Enums\DuplicateLevel;
use SaniTube\Ui\Queries\DuplicateReviewQuery;

/**
 * The filters the duplicate queue accepts, and the ones it refuses.
 */
final class DuplicateIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route's `auth` and `active` middleware own this.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'decision' => ['nullable', Rule::enum(DuplicateDecision::class)],
            'level' => ['nullable', Rule::enum(DuplicateLevel::class)],
            'cursor' => [
                'nullable',
                'string',
                'max:512',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && $value !== '' && ! DuplicateReviewQuery::describesThisOrdering($value)) {
                        $fail('The pagination cursor is not one this list issued.');
                    }
                },
            ],
        ];
    }

    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(response()->json([
            'message' => 'These filters are not ones the queue understands.',
            'errors' => $validator->errors()->toArray(),
        ], 422));
    }

    /**
     * @return array<string, string|null>
     */
    public function filters(): array
    {
        $filters = [];

        foreach (['decision', 'level'] as $key) {
            $value = $this->validated($key);
            $value = is_string($value) ? trim($value) : null;

            $filters[$key] = ($value === null || $value === '') ? null : $value;
        }

        return $filters;
    }

    public function cursor(): ?string
    {
        $cursor = $this->validated('cursor');

        return is_string($cursor) && $cursor !== '' ? $cursor : null;
    }
}
