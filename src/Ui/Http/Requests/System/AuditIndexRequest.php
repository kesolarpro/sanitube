<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Requests\System;

use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use SaniTube\Audit\Enums\AuditAction;
use SaniTube\Audit\Enums\AuditOutcome;
use SaniTube\Audit\Enums\AuditSubject;
use SaniTube\Ui\Queries\AuditIndexQuery;

/**
 * The filters the audit log understands.
 *
 * Each enumerated one is validated against its enum before the query is built,
 * because `AuditAction::from()` on an unrecognised string is a `ValueError` and
 * a 500 — and because the alternative, a `LIKE` on a caller-supplied string,
 * is a filter somebody can shape into a scan.
 *
 * `subject_uuid` is the interesting one: it is how "everything ever done to
 * this release" is asked. It is validated as a uuid and matched exactly, so it
 * cannot become a prefix search across other people's objects.
 */
final class AuditIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route is behind `can.role:administer`. Repeating it here would
        // be a second copy of an authorisation decision, and the second copy
        // is the one that drifts.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'action' => ['nullable', Rule::enum(AuditAction::class)],
            'subject' => ['nullable', Rule::enum(AuditSubject::class)],
            'outcome' => ['nullable', Rule::enum(AuditOutcome::class)],
            'subject_uuid' => ['nullable', 'uuid'],

            'cursor' => [
                'nullable', 'string', 'max:512',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && $value !== '' && ! AuditIndexQuery::describesThisOrdering($value)) {
                        $fail('The pagination cursor is not one this list issued.');
                    }
                },
            ],
        ];
    }

    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(response()->json([
            'message' => 'These filters are not ones the audit log understands.',
            'errors' => $validator->errors()->toArray(),
        ], 422));
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        $filters = [];

        foreach (['action', 'subject', 'outcome', 'subject_uuid'] as $key) {
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
