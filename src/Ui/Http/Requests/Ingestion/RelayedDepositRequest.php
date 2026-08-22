<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Requests\Ingestion;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use SaniTube\Ui\Http\Requests\Concerns\RefusesDiscardedUploads;

/**
 * One file relayed through the application into the inbox.
 *
 * The path a shared account without object storage has, and the reason it is
 * kept: an install on cPanel cannot mint an upload URL, and an import tool
 * that only worked with object storage would be an import tool that did not
 * work on the hosting this platform is meant to run on.
 *
 * No `max` rule on the file. The ceiling is `assets.max_upload_bytes` read
 * through `UploadAdmission`, and stating it a second time here would be a copy
 * that eventually disagrees with the one the direct path uses.
 */
final class RelayedDepositRequest extends FormRequest
{
    use RefusesDiscardedUploads;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file'],
        ];
    }

    public function upload(): UploadedFile
    {
        /** @var UploadedFile $file */
        $file = $this->file('file');

        return $file;
    }
}
