<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Requests\Ingestion;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Declining a proposal, with the reason that makes it revisitable.
 *
 * The reason is `required` here as well as in the domain, and the duplication
 * is deliberate rather than accidental: the domain refusal is the guarantee,
 * and this one exists so a reviewer who leaves the box empty sees the field go
 * red instead of a page-level error about a decision they thought they had
 * described.
 */
final class RejectCandidateRequest extends FormRequest
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
            'reason' => ['required', 'string', 'min:1', 'max:2000'],
        ];
    }

    public function reason(): string
    {
        return trim((string) $this->input('reason'));
    }
}
