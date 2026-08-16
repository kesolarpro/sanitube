<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Requests\Distribution;

use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use SaniTube\Distribution\Enums\DistributionDeliveryStatus;
use SaniTube\Ui\Queries\DeliveryIndexQuery;

final class DeliveryIndexRequest extends FormRequest
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
            'status' => ['nullable', Rule::enum(DistributionDeliveryStatus::class)],

            // Not validated against the configured names. A delivery outlives
            // a configuration change, and filtering by a provider that has
            // since been removed is exactly how somebody finds the rows it
            // left behind.
            'provider' => ['nullable', 'string', 'max:64'],

            'cursor' => [
                'nullable', 'string', 'max:512',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && $value !== '' && ! DeliveryIndexQuery::describesThisOrdering($value)) {
                        $fail('The pagination cursor is not one this list issued.');
                    }
                },
            ],
        ];
    }

    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(response()->json([
            'message' => 'These filters are not ones the delivery list understands.',
            'errors' => $validator->errors()->toArray(),
        ], 422));
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        $filters = [];

        foreach (['status', 'provider'] as $key) {
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
