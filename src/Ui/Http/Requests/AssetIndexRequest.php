<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Requests;

use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use SaniTube\Assets\Enums\AssetKind;
use SaniTube\Assets\Enums\AssetStatus;
use SaniTube\Ui\Queries\AssetIndexQuery;

/**
 * The filters the asset list accepts, and the ones it refuses.
 */
final class AssetIndexRequest extends FormRequest
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
            'kind' => ['nullable', Rule::enum(AssetKind::class)],
            'status' => ['nullable', Rule::enum(AssetStatus::class)],
            'duplicates' => ['nullable', 'in:0,1,'],
            'trashed' => ['nullable', 'in:only,all,'],
            'cursor' => [
                'nullable',
                'string',
                'max:512',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && $value !== '' && ! AssetIndexQuery::describesThisOrdering($value)) {
                        $fail('The pagination cursor is not one this list issued.');
                    }
                },
            ],
        ];
    }

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

        foreach (['kind', 'status'] as $key) {
            $value = $this->validated($key);
            $value = is_string($value) ? trim($value) : null;

            $filters[$key] = ($value === null || $value === '') ? null : $value;
        }

        $duplicates = $this->validated('duplicates');
        $filters['duplicates'] = ($duplicates === '0' || $duplicates === '1') ? $duplicates === '1' : null;

        $trashed = $this->validated('trashed');
        $filters['trashed'] = ($trashed === 'only' || $trashed === 'all') ? $trashed : null;

        return $filters;
    }

    public function cursor(): ?string
    {
        $cursor = $this->validated('cursor');

        return is_string($cursor) && $cursor !== '' ? $cursor : null;
    }
}
