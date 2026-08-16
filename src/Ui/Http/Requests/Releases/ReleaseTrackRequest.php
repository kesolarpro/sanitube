<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Requests\Releases;

use Illuminate\Foundation\Http\FormRequest;

final class ReleaseTrackRequest extends FormRequest
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
            'track' => ['required', 'string', 'size:36'],
            'disc' => ['sometimes', 'integer', 'min:1', 'max:99'],
            'is_focus_track' => ['sometimes', 'boolean'],
        ];
    }

    public function trackUuid(): string
    {
        return (string) $this->input('track');
    }

    public function disc(): int
    {
        return max(1, (int) $this->input('disc', 1));
    }

    public function isFocusTrack(): bool
    {
        return $this->boolean('is_focus_track');
    }
}
