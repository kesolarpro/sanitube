<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Requests\Releases;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The cover, named by asset uuid.
 *
 * Whether that asset is ARTWORK and VERIFIED is the builder's question — it
 * refuses anything else, and the refusal is what stops a store receiving a
 * corrupt image or an audio file.
 */
final class ReleaseCoverRequest extends FormRequest
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
        return ['asset' => ['required', 'string', 'size:36']];
    }

    public function assetUuid(): string
    {
        return (string) $this->input('asset');
    }
}
