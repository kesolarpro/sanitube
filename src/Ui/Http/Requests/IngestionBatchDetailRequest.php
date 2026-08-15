<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Requests;

use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use SaniTube\Ui\Queries\IngestionBatchDetailQuery;

/**
 * The item list inside one batch takes no filters — only a cursor.
 *
 * It exists so that a cursor copied from another list is refused with a 422
 * naming the problem, rather than reaching the paginator and surfacing as a
 * 500 about an unexpected value. A mangled URL is the caller's mistake, and
 * answering it with a server error hides that.
 */
final class IngestionBatchDetailRequest extends FormRequest
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
            'cursor' => [
                'nullable',
                'string',
                'max:512',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && $value !== '' && ! IngestionBatchDetailQuery::describesThisOrdering($value)) {
                        $fail('The pagination cursor is not one this list issued.');
                    }
                },
            ],
        ];
    }

    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(response()->json([
            'message' => 'This pagination cursor is not one the item list understands.',
            'errors' => $validator->errors()->toArray(),
        ], 422));
    }

    public function cursor(): ?string
    {
        $cursor = $this->validated('cursor');

        return is_string($cursor) && $cursor !== '' ? $cursor : null;
    }
}
