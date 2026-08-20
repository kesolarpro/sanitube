<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Requests\Assets;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use SaniTube\Assets\Enums\AssetKind;

/**
 * What a browser may ask to upload.
 *
 * The kind is an enum, because it decides the size limit and the canonical key
 * prefix — a free string here would let a caller register a master under the
 * artwork limit.
 *
 * The filename is metadata and is treated as such: it is stored and displayed,
 * it is never the identity, and it never reaches the object key. That is
 * `AssetObjectKeys`' job and it derives the key from the asset's uuid, which
 * is why two files genuinely called `song.mp3` cannot collide.
 */
final class BeginUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route is behind `can.role:catalogue`. A second copy of an
        // authorisation decision is the copy that drifts.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::enum(AssetKind::class)],
            // Bounded because it is stored and rendered, not because it is
            // trusted. Nothing downstream parses it.
            'filename' => ['required', 'string', 'max:255'],
        ];
    }

    public function kind(): string
    {
        return (string) $this->validated('kind');
    }

    public function filename(): string
    {
        return trim((string) $this->validated('filename'));
    }
}
