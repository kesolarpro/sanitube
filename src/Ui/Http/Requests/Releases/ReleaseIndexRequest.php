<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Requests\Releases;

use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use SaniTube\Releases\Enums\ReleaseStatus;
use SaniTube\Releases\Enums\ReleaseType;
use SaniTube\Ui\Queries\ReleaseIndexQuery;

final class ReleaseIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::enum(ReleaseStatus::class)],
            'type' => ['nullable', Rule::enum(ReleaseType::class)],
            'cursor' => [
                'nullable', 'string', 'max:512',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && $value !== '' && ! ReleaseIndexQuery::describesThisOrdering($value)) {
                        $fail('The pagination cursor is not one this list issued.');
                    }
                },
            ],
        ];
    }

    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(response()->json([
            'message' => 'These filters are not ones the release list understands.',
            'errors' => $validator->errors()->toArray(),
        ], 422));
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        $filters = [];

        foreach (['status', 'type'] as $key) {
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
