<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Requests\Releases;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A prompt for a cover.
 *
 * Bounded, and that bound is a cost control as much as a validation: a prompt
 * is what the platform pays a provider to read, and an unbounded one is an
 * unbounded request.
 *
 * The prompt is catalogue data — somebody's description of their own record —
 * so it is stored as written and never appears in a log or an exception
 * message, for the reason `ProviderFailure` gives about generation.
 */
final class GenerateCoverRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'prompt' => ['required', 'string', 'min:8', 'max:2000'],
            'quality' => ['sometimes', 'nullable', 'string', 'max:32'],
        ];
    }

    public function prompt(): string
    {
        $prompt = $this->input('prompt');

        return is_string($prompt) ? trim($prompt) : '';
    }

    public function quality(): ?string
    {
        $quality = $this->input('quality');

        return is_string($quality) && trim($quality) !== '' ? trim($quality) : null;
    }
}
