<?php

declare(strict_types=1);

namespace SaniTube\Api\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use SaniTube\Releases\Enums\ReleaseType;

/**
 * Editing a draft's metadata.
 *
 * `status` is deliberately absent. Readiness is earned by passing validation,
 * not assigned — a settable status field would make I4 optional.
 */
final class UpdateReleaseRequest extends FormRequest
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
            'title' => ['sometimes', 'string', 'max:255'],
            'version_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'type' => ['sometimes', Rule::enum(ReleaseType::class)],
            'label_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'catalogue_number' => ['sometimes', 'nullable', 'string', 'max:64'],
            'release_date' => ['sometimes', 'nullable', 'date'],
            'original_release_date' => ['sometimes', 'nullable', 'date'],
            'language_code' => ['sometimes', 'string', 'max:8'],
            'p_line' => ['sometimes', 'nullable', 'string', 'max:255'],
            'c_line' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
