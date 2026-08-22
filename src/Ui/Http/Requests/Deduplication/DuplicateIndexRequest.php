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
 *
 * **A queue with no default is a queue nobody finishes.** DUP-001: this list
 * had none, so every finding a reviewer had already confirmed or rejected came
 * back on the next visit, and the only way to see what was left was to select
 * "proposed" again, every time. A backlog that never visibly shrinks is one
 * people stop opening.
 *
 * So an absent `decision` means **undecided**, and an *empty* one means all.
 * The distinction is between a key that is not in the query string and a key
 * that is there with nothing after it — `?decision=` — which is what the "all"
 * option on the screen sends. It is the one place this request cares that
 * Laravel turns an empty string into null before it arrives, so the key's
 * presence is read from the input rather than from the validated value.
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

        // Nothing said at all means the work that is left. Saying it and
        // leaving it empty means everything, decided or not — which is how a
        // reviewer looks back at what they already answered.
        if ($filters['decision'] === null && ! $this->has('decision')) {
            $filters['decision'] = DuplicateDecision::Proposed->value;
        }

        return $filters;
    }

    public function cursor(): ?string
    {
        $cursor = $this->validated('cursor');

        return is_string($cursor) && $cursor !== '' ? $cursor : null;
    }
}
