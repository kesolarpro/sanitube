<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Requests\Ingestion;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Correcting a suggestion before anybody acts on it.
 *
 * Only `suggested_title` is editable from the interface. The candidate's
 * `metadata` is where the importer's evidence lives — what the file said, what
 * the manifest claimed, what the platform noticed — and a free-form editor
 * over it would let a reviewer rewrite the evidence their own decision is
 * supposed to rest on.
 */
final class ReviseCandidateRequest extends FormRequest
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
            'suggested_title' => ['required', 'string', 'min:1', 'max:255'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function revisions(): array
    {
        return ['suggested_title' => trim((string) $this->input('suggested_title'))];
    }
}
