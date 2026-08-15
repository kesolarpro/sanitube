<?php

declare(strict_types=1);

namespace SaniTube\Api\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A request to generate music.
 *
 * No provider credential, model identifier or endpoint is accepted from a
 * caller. `provider` names a *configured* provider by its SaniTube name, and
 * an unknown one is refused by the manager rather than attempted.
 */
final class StoreMusicGenerationRequest extends FormRequest
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
            'prompt' => ['required', 'string', 'max:4000'],
            'project' => ['sometimes', 'nullable', 'string', 'size:36'],
            'style_prompt' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'lyrics' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'instrumental' => ['sometimes', 'boolean'],
            'language_code' => ['sometimes', 'nullable', 'string', 'max:8'],
            'model' => ['sometimes', 'nullable', 'string', 'max:128'],
            'parameters' => ['sometimes', 'array'],
            'provider' => ['sometimes', 'string', 'max:64'],
        ];
    }
}
