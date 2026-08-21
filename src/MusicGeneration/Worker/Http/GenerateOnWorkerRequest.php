<?php

declare(strict_types=1);

namespace SaniTube\MusicGeneration\Worker\Http;

use Illuminate\Foundation\Http\FormRequest;

/**
 * What a caller may ask a generation worker for.
 *
 * **The list is the security boundary**, and it is short on purpose. There is
 * no field here for a checkpoint, a device, an output path, a model, a step
 * count or a guidance scale, because every one of those is a decision this
 * worker makes from its own configuration. A request that could set them would
 * be a request that can read a file, choose a GPU, or make one generation cost
 * fifty times another.
 *
 * `reference` is an identity Core supplies so the worker can name its temporary
 * file and its staged object. It is sanitised to hex before it touches either.
 */
final class GenerateOnWorkerRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The token middleware is the authorisation. Repeating it here would
        // be a second place for it to be wrong.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reference' => ['required', 'string', 'max:64'],
            'prompt' => ['required', 'string', 'min:1', 'max:4000'],
            'style_prompt' => ['nullable', 'string', 'max:2000'],
            'lyrics' => ['nullable', 'string', 'max:20000'],
            'instrumental' => ['nullable', 'boolean'],
            'language' => ['nullable', 'string', 'max:8'],
        ];
    }

    public function reference(): string
    {
        return (string) $this->validated('reference');
    }

    public function prompt(): string
    {
        return (string) $this->validated('prompt');
    }

    public function stylePrompt(): ?string
    {
        $value = $this->validated('style_prompt');

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    public function lyrics(): ?string
    {
        $value = $this->validated('lyrics');

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    public function instrumental(): bool
    {
        return (bool) $this->validated('instrumental');
    }

    public function languageCode(): ?string
    {
        $value = $this->validated('language');

        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
