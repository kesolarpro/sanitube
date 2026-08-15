<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Requests;

use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use SaniTube\Artists\Enums\ArtistStatus;
use SaniTube\Artists\Enums\ArtistType;
use SaniTube\Ui\Queries\ArtistIndexQuery;

/**
 * The filters the artist list accepts, and the ones it refuses.
 *
 * Same contract as {@see TrackIndexRequest}: an unrecognised value is a 422,
 * never a silently discarded parameter that leaves the form claiming a filter
 * it did not apply.
 */
final class ArtistIndexRequest extends FormRequest
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
            'status' => ['nullable', Rule::enum(ArtistStatus::class)],
            'type' => ['nullable', Rule::enum(ArtistType::class)],
            'country' => ['nullable', 'string', 'size:2', 'alpha'],
            'owner_controlled' => ['nullable', 'in:0,1,'],
            'cursor' => [
                'nullable',
                'string',
                'max:512',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && $value !== '' && ! ArtistIndexQuery::describesThisOrdering($value)) {
                        $fail('The pagination cursor is not one this list issued.');
                    }
                },
            ],
        ];
    }

    /**
     * A refused filter answers 422 rather than redirecting.
     *
     * See TrackIndexRequest for the reasoning, and for the limitation this
     * currently carries: the 422 reaches the browser as JSON rather than as an
     * error inside the filter form.
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

        foreach (['search', 'status', 'type', 'country'] as $key) {
            $value = $this->validated($key);
            $value = is_string($value) ? trim($value) : null;

            $filters[$key] = ($value === null || $value === '') ? null : $value;
        }

        $owned = $this->validated('owner_controlled');
        $filters['owner_controlled'] = ($owned === '0' || $owned === '1') ? $owned === '1' : null;

        return $filters;
    }

    public function cursor(): ?string
    {
        $cursor = $this->validated('cursor');

        return is_string($cursor) && $cursor !== '' ? $cursor : null;
    }
}
