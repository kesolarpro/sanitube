<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Requests\Distribution;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A person's account of what a distributor actually holds.
 *
 * The note is required by the form as well as by the domain, because it is the
 * field a form can usefully complain about in place. "It arrived, and here is
 * the reference" without a word about where that was checked is a claim
 * nobody can review later.
 *
 * `external_release_id` is *only* required when the answer is "it arrived",
 * and that rule lives in the builder rather than here: it is a property of the
 * decision, not of the form.
 */
final class ResolveDeliveryRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'arrived' => ['required', 'boolean'],
            'external_release_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'note' => ['required', 'string', 'min:5', 'max:2000'],
        ];
    }

    public function arrived(): bool
    {
        return $this->boolean('arrived');
    }

    public function externalReleaseId(): ?string
    {
        $value = $this->input('external_release_id');

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    public function note(): string
    {
        $note = $this->input('note');

        return is_string($note) ? trim($note) : '';
    }
}
