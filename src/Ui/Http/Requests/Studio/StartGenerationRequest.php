<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Requests\Studio;

use Illuminate\Foundation\Http\FormRequest;
use SaniTube\MusicGeneration\Services\StartMusicGeneration;

/**
 * Asking a supplier to make something.
 *
 * The prompt is the only required field: everything else either has a project
 * default or is genuinely optional. `provider` is deliberately absent — the
 * configured default is the one the studio uses, and letting a form name an
 * arbitrary provider would make "which supplier was this billed to" a question
 * about a request rather than about configuration.
 *
 * Lyrics and `instrumental` contradict each other by construction, and the
 * contradiction is resolved in {@see StartMusicGeneration}
 * — which discards lyrics for an instrumental — rather than being validated
 * into an error here. A reviewer who ticks "instrumental" after typing lyrics
 * has changed their mind, not made a mistake.
 */
final class StartGenerationRequest extends FormRequest
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
            'prompt' => ['required', 'string', 'min:1', 'max:4000'],
            'style_prompt' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'lyrics' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'instrumental' => ['sometimes', 'boolean'],
            'language_code' => ['sometimes', 'nullable', 'string', 'max:8'],
            'project' => ['sometimes', 'nullable', 'string', 'size:36'],
        ];
    }

    public function prompt(): string
    {
        return trim((string) $this->input('prompt'));
    }

    public function stylePrompt(): ?string
    {
        return $this->text('style_prompt');
    }

    public function lyrics(): ?string
    {
        return $this->text('lyrics');
    }

    public function instrumental(): bool
    {
        return $this->boolean('instrumental');
    }

    public function languageCode(): ?string
    {
        return $this->text('language_code');
    }

    public function projectUuid(): ?string
    {
        return $this->text('project');
    }

    private function text(string $key): ?string
    {
        $value = $this->input($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
