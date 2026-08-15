<?php

declare(strict_types=1);

namespace SaniTube\Api\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Correcting a suggestion before anyone acts on it.
 *
 * Only the fields a candidate is allowed to hold: a suggested title and
 * incidental metadata. Nothing here can set a status, choose an asset or name
 * a track — those are decisions, and a PATCH is not where decisions are taken.
 */
final class ReviseTrackCandidateRequest extends FormRequest
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
            'suggested_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'metadata' => ['sometimes', 'array'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function revisions(): array
    {
        return $this->safe()->only(['suggested_title', 'metadata']);
    }
}
