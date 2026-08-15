<?php

declare(strict_types=1);

namespace SaniTube\Api\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

final class StoreGenerationProjectRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            // Recorded, never enforced. It says what somebody set out to make,
            // so "we wanted forty and got thirty-one" is answerable.
            'target_track_count' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'default_language' => ['sometimes', 'nullable', 'string', 'max:8'],
            'default_genre' => ['sometimes', 'nullable', 'string', 'max:64'],
            'default_style_prompt' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
