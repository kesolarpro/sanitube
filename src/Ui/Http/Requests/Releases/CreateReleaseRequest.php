<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Requests\Releases;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use SaniTube\Releases\Enums\ReleaseType;

/**
 * Starting a release.
 *
 * Only the title and the type are required. Everything else is filled in as
 * the release is assembled, which is the point of a builder — demanding a
 * release date before a label has decided one would make the first screen a
 * wall.
 */
final class CreateReleaseRequest extends FormRequest
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
            'title' => ['required', 'string', 'min:1', 'max:255'],
            'type' => ['required', Rule::enum(ReleaseType::class)],
            'version_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'language_code' => ['sometimes', 'nullable', 'string', 'max:8'],
        ];
    }

    public function title(): string
    {
        return trim((string) $this->input('title'));
    }

    public function type(): string
    {
        return (string) $this->input('type');
    }

    public function versionTitle(): ?string
    {
        return $this->text('version_title');
    }

    public function languageCode(): ?string
    {
        return $this->text('language_code');
    }

    private function text(string $key): ?string
    {
        $value = $this->input($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
