<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Requests\Releases;

use Illuminate\Foundation\Http\FormRequest;

final class ReleasePickerRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // Capped rather than unbounded: a search term is not a place to accept
        // arbitrary input, and nothing legitimate is longer than a title.
        return ['q' => ['sometimes', 'nullable', 'string', 'max:255']];
    }

    public function term(): string
    {
        $term = $this->input('q');

        return is_string($term) ? trim($term) : '';
    }
}
