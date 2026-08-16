<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Requests\Studio;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Starting a campaign.
 *
 * Only the name is required. A project exists to say a style, a language and a
 * target once instead of forty times, and somebody who has not decided any of
 * those yet should still be able to name the campaign and start.
 */
final class CreateProjectRequest extends FormRequest
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
            'name' => ['required', 'string', 'min:1', 'max:255'],
            'target_track_count' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10000'],
            'default_language' => ['sometimes', 'nullable', 'string', 'max:8'],
            'default_genre' => ['sometimes', 'nullable', 'string', 'max:64'],
            'default_style_prompt' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
