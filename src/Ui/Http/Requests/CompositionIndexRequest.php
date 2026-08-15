<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Requests;

use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use SaniTube\Catalog\Enums\CompositionStatus;
use SaniTube\Ui\Queries\CompositionIndexQuery;

/**
 * The filters the compositions list accepts, and the ones it refuses.
 *
 * Same contract as the track and artist lists: an unrecognised value is a 422,
 * never a silently discarded parameter that leaves the form claiming a filter
 * it did not apply.
 */
final class CompositionIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route's `auth` and `active` middleware from SEC-001 own this.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:191'],
            'status' => ['nullable', Rule::enum(CompositionStatus::class)],
            'language' => ['nullable', 'string', 'max:16'],
            'public_domain' => ['nullable', 'in:0,1,'],
            'cursor' => [
                'nullable',
                'string',
                'max:512',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && $value !== '' && ! CompositionIndexQuery::describesThisOrdering($value)) {
                        $fail('The pagination cursor is not one this list issued.');
                    }
                },
            ],
        ];
    }

    /**
     * A refused filter answers 422 rather than redirecting.
     *
     * See TrackIndexRequest for the reasoning and for the limitation this
     * carries: the 422 currently reaches the browser as JSON rather than as an
     * error rendered inside the filter form.
     */
    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(response()->json([
            'message' => 'These filters are not ones the catalogue understands.',
            'errors' => $validator->errors()->toArray(),
        ], 422));
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        $filters = [];

        foreach (['search', 'status', 'language'] as $key) {
            $value = $this->validated($key);
            $value = is_string($value) ? trim($value) : null;

            $filters[$key] = ($value === null || $value === '') ? null : $value;
        }

        foreach (['public_domain'] as $key) {
            $value = $this->validated($key);

            $filters[$key] = ($value === '0' || $value === '1') ? $value === '1' : null;
        }

        return $filters;
    }

    public function cursor(): ?string
    {
        $cursor = $this->validated('cursor');

        return is_string($cursor) && $cursor !== '' ? $cursor : null;
    }
}
