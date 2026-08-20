<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Requests\Assets;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use SaniTube\Assets\Enums\AssetKind;

/**
 * One file, handed to the application rather than straight to the provider.
 *
 * The kind is an enum for the same reason `BeginUploadRequest` gives: a free
 * string here would let a caller register a master under the artwork ceiling.
 *
 * **The rules deliberately stop short of the media type.** Laravel's `mimes`
 * rule can be satisfied by an extension, and the whole point of checking is
 * that the extension is the uploader's word for it. The type is read from the
 * bytes in the controller, through `UploadAdmission`.
 */
final class RelayedUploadRequest extends FormRequest
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
            'file' => ['required', 'file'],
        ];
    }

    public function kind(): AssetKind
    {
        return AssetKind::from((string) $this->input('kind'));
    }

    public function upload(): UploadedFile
    {
        $file = $this->file('file');

        // Narrowed for the type system. `rules()` has already refused anything
        // that is not a single uploaded file.
        return $file instanceof UploadedFile ? $file : throw new \LogicException('No uploaded file.');
    }
}
