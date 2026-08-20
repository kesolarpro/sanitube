<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Withdrawing an identifier, with the reason it was withdrawn.
 *
 * The reason is required by the form as well as by the domain. An identifier
 * that reached a distributor exists in reports and store metadata SaniTube does
 * not control; the row is kept for ever and the revocation is the only record
 * of why it stopped being true. "Revoked, no reason given" is a row nobody can
 * act on years later, which is precisely when it will be read.
 */
final class RevokeIdentifierRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    public function reason(): string
    {
        $reason = $this->input('reason');

        return is_string($reason) ? trim($reason) : '';
    }
}
