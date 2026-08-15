<?php

declare(strict_types=1);

namespace SaniTube\Api\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Declining a proposal, with the reason required rather than encouraged.
 *
 * "Rejected" on its own is a decision nobody can revisit, and revisiting is
 * exactly what happens when a distributor later asks for the recording that
 * was thrown out.
 */
final class RejectTrackCandidateRequest extends FormRequest
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
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ];
    }

    public function reason(): string
    {
        return trim((string) $this->input('reason'));
    }
}
