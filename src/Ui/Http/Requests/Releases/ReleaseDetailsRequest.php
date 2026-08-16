<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Requests\Releases;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use SaniTube\Releases\Enums\ReleaseType;

/**
 * The release's own metadata.
 *
 * Which of these a given status still accepts is `ReleaseMutationPolicy`'s
 * question, asked by the builder on every mutation. This request only bounds
 * the shapes — a second copy of the policy here is the copy that disagrees.
 *
 * `p_line` and `c_line` are catalogue metadata: the ℗ and © notices a store
 * prints. They carry no financial meaning and trigger no calculation.
 */
final class ReleaseDetailsRequest extends FormRequest
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
            'title' => ['sometimes', 'string', 'min:1', 'max:255'],
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

    /**
     * @return array<string, mixed>
     */
    public function details(): array
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->safe()->all();

        $details = [];

        foreach ($validated as $key => $value) {
            // An empty text input clears the field rather than setting it to
            // "". A stored empty string reads as a stated value and would be
            // printed by a store as one.
            $details[$key] = is_string($value) && trim($value) === '' ? null : $value;
        }

        return $details;
    }
}
