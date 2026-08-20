<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;

/**
 * An identifier a person is holding and is typing in.
 *
 * Two fields, and neither of them is `source`. Provenance is chosen by the
 * server — always MANUAL — because a request that could name its own source
 * could claim a distributor issued the value, and a forged DISTRIBUTOR
 * provenance is indistinguishable afterwards from a real one. `is_authoritative`
 * is withheld for the same reason.
 *
 * The *shape* of the value is not checked here. `ExternalIdentifierNormaliser`
 * knows what a well-formed ISRC is, checksum included, and a second opinion in
 * a form request would be a copy that eventually disagrees with the one the
 * database is protected by. This bounds the string and stops there.
 */
final class AssignIdentifierRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', 'max:64'],
            'value' => ['required', 'string', 'max:255'],
        ];
    }

    public function type(): string
    {
        $type = $this->input('type');

        return is_string($type) ? trim($type) : '';
    }

    public function value(): string
    {
        $value = $this->input('value');

        return is_string($value) ? trim($value) : '';
    }
}
