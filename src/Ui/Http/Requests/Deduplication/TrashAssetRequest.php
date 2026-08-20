<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Requests\Deduplication;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Why a master is being set aside.
 *
 * A code from a closed list, never free text. The column outlives whatever
 * English was current, an operator filtering a year of trash needs values that
 * group, and a reason a reviewer typed is a reason nobody can count.
 */
final class TrashAssetRequest extends FormRequest
{
    /**
     * The reasons a person may give.
     *
     * `OTHER` exists so that the list can stay short without forcing a wrong
     * answer — an operator who reaches for it repeatedly is telling whoever
     * maintains this list that it is missing a case.
     */
    public const REASONS = ['DUPLICATE', 'SUPERSEDED', 'WRONG_UPLOAD', 'UNUSABLE_AUDIO', 'OTHER'];

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
            'reason' => ['required', 'string', Rule::in(self::REASONS)],
        ];
    }

    public function reason(): string
    {
        $reason = $this->validated('reason');

        return is_string($reason) ? $reason : 'OTHER';
    }
}
